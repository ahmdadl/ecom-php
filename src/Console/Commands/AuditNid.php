<?php

namespace HZ\Illuminate\Mongez\Console\Commands;

use Illuminate\Console\Command;
use HZ\Illuminate\Mongez\Support\NidAuditScanner;

class AuditNid extends Command
{
    protected $signature = 'mongez:audit-nid
        {--path=app : Relative or absolute path to scan (default: app/)}
        {--json : Emit findings as JSON}';

    protected $description = 'Audit app code for legacy MongoDB integer `id` usage during the nid cutover';

    public function handle(): int
    {
        $rawPath = $this->option('path');
        $pathOption = is_string($rawPath) ? $rawPath : 'app';
        $path = $this->resolvePath($pathOption);

        if (! is_dir($path) && ! is_file($path)) {
            $this->error("Path not found: {$path}");

            return self::FAILURE;
        }

        $scanner = new NidAuditScanner();
        $findings = $scanner->scan($path);

        if ($this->option('json')) {
            $json = json_encode([
                'path' => $path,
                'count' => count($findings),
                'findings' => $findings,
                'summary' => $this->summarize($findings),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $this->line($json !== false ? $json : '{}');

            return $findings === [] ? self::SUCCESS : self::FAILURE;
        }

        if ($findings === []) {
            $this->info("No legacy `id` findings under {$path}");

            return self::SUCCESS;
        }

        $this->warn(sprintf('Found %d legacy `id` finding(s) under %s', count($findings), $path));
        $this->newLine();

        foreach ($this->summarize($findings) as $category => $count) {
            $this->line(sprintf('  %-16s %d', $category, $count));
        }

        $this->newLine();

        foreach ($findings as $finding) {
            $relative = $this->relativePath($finding['file']);
            $this->line(sprintf(
                '[%s] %s:%d  %s',
                $finding['category'],
                $relative,
                $finding['line'],
                $finding['match']
            ));
        }

        $this->newLine();
        $this->comment('Heuristic scan — triage false positives, then rename to `nid` and disable mongez.mongodb.id_aliases_nid.');

        return self::FAILURE;
    }

    private function resolvePath(string $path): string
    {
        if ($path === '' || $path === 'app') {
            return base_path('app');
        }

        if (str_starts_with($path, DIRECTORY_SEPARATOR) || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1) {
            return $path;
        }

        return base_path($path);
    }

    /**
     * @param list<array{category: string, file: string, line: int, match: string}> $findings
     * @return array<string, int>
     */
    private function summarize(array $findings): array
    {
        $summary = [];

        foreach ($findings as $finding) {
            $category = $finding['category'];
            $summary[$category] = ($summary[$category] ?? 0) + 1;
        }

        ksort($summary);

        return $summary;
    }

    private function relativePath(string $file): string
    {
        $base = str_replace('\\', '/', base_path());
        $normalized = str_replace('\\', '/', $file);

        if (str_starts_with($normalized, $base . '/')) {
            return substr($normalized, strlen($base) + 1);
        }

        return $file;
    }
}
