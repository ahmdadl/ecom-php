<?php

namespace HZ\Illuminate\Mongez\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;
use SplFileInfo;

/**
 * Heuristic scanner for legacy integer-identity `id` usage during the `nid` cutover.
 *
 * Findings are intentionally noisy; triage against known-safe false positives
 * (request ids, third-party APIs, etc.) before treating exit code 1 as a hard gate.
 */
class NidAuditScanner
{
    public const CATEGORY_PROPERTY_READ = 'property_read';

    public const CATEGORY_QUERY = 'query';

    public const CATEGORY_ARRAY_KEY = 'array_key';

    public const CATEGORY_CONSTANT = 'constant';

    public const CATEGORY_EMBEDDED_PATH = 'embedded_path';

    /**
     * @var list<string>
     */
    private array $extensions;

    /**
     * @param list<string> $extensions
     */
    public function __construct(array $extensions = ['php'])
    {
        $this->extensions = $extensions;
    }

    /**
     * @return list<array{category: string, file: string, line: int, match: string}>
     */
    public function scan(string $path): array
    {
        if (! is_dir($path) && ! is_file($path)) {
            return [];
        }

        $findings = [];

        foreach ($this->files($path) as $file) {
            $contents = @file_get_contents($file);

            if ($contents === false) {
                continue;
            }

            $findings = array_merge($findings, $this->scanContents($file, $contents));
        }

        usort($findings, static function (array $a, array $b): int {
            return [$a['file'], $a['line'], $a['category']]
                <=> [$b['file'], $b['line'], $b['category']];
        });

        return $findings;
    }

    /**
     * @return list<array{category: string, file: string, line: int, match: string}>
     */
    public function scanContents(string $file, string $contents): array
    {
        $findings = [];
        $lines = preg_split("/\r\n|\n|\r/", $contents) ?: [];
        $inIdentityConstant = null;
        $bracketDepth = 0;

        foreach ($lines as $index => $line) {
            $lineNumber = $index + 1;
            $trimmed = trim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '#')) {
                continue;
            }

            if ($inIdentityConstant === null && preg_match(
                '/\bconst\s+(INTEGER_DATA|STRING_DATA|SHARED_INFO)\s*=/',
                $line,
                $constMatch
            )) {
                $inIdentityConstant = $constMatch[1];
                $bracketDepth = 0;
            }

            if ($inIdentityConstant !== null) {
                $bracketDepth += substr_count($line, '[') - substr_count($line, ']');

                if (preg_match("/['\"]id['\"]/", $line, $idMatch)) {
                    $findings[] = [
                        'category' => self::CATEGORY_CONSTANT,
                        'file' => $file,
                        'line' => $lineNumber,
                        'match' => sprintf("const %s ... %s", $inIdentityConstant, $idMatch[0]),
                    ];
                }

                if ($bracketDepth <= 0 && str_contains($line, ';')) {
                    $inIdentityConstant = null;
                }

                // Still run other matchers on the same line (property/query/etc.)
            }

            foreach ($this->matchLine($line, $inIdentityConstant !== null) as [$category, $match]) {
                $findings[] = [
                    'category' => $category,
                    'file' => $file,
                    'line' => $lineNumber,
                    'match' => $match,
                ];
            }
        }

        return $findings;
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function matchLine(string $line, bool $insideIdentityConstant = false): array
    {
        $matches = [];

        if (preg_match("/(?:where|whereIn|orWhere|orderBy|orderByDesc|groupBy)\s*\(\s*['\"]id['\"]/", $line, $m)) {
            $matches[] = [self::CATEGORY_QUERY, $m[0]];
        }

        if (preg_match("/['\"][\\w.]*\\.id['\"]/", $line, $m)) {
            $matches[] = [self::CATEGORY_EMBEDDED_PATH, $m[0]];
        }

        if (preg_match('/->id\b(?!\s*\()/', $line, $m)) {
            $matches[] = [self::CATEGORY_PROPERTY_READ, $m[0]];
        }

        if (! $insideIdentityConstant && preg_match("/\\[['\"]id['\"]\\]/", $line, $m)) {
            $alreadyCovered = false;

            foreach ($matches as [$category]) {
                if (in_array($category, [self::CATEGORY_QUERY, self::CATEGORY_EMBEDDED_PATH], true)) {
                    $alreadyCovered = true;
                    break;
                }
            }

            if (! $alreadyCovered) {
                $matches[] = [self::CATEGORY_ARRAY_KEY, $m[0]];
            }
        }

        return $matches;
    }

    /**
     * @return list<string>
     */
    private function files(string $path): array
    {
        if (is_file($path)) {
            return [$path];
        }

        $directory = new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS);
        $iterator = new RecursiveIteratorIterator($directory);
        $phpFiles = new RegexIterator($iterator, '/^.+\.php$/i', RegexIterator::GET_MATCH);

        $files = [];

        /** @var array{0: string} $match */
        foreach ($phpFiles as $match) {
            $file = $match[0];

            if (! $this->shouldInclude(new SplFileInfo($file))) {
                continue;
            }

            $files[] = $file;
        }

        sort($files);

        return $files;
    }

    private function shouldInclude(SplFileInfo $file): bool
    {
        $extension = strtolower($file->getExtension());

        if (! in_array($extension, $this->extensions, true)) {
            return false;
        }

        $pathname = str_replace('\\', '/', $file->getPathname());

        foreach (['/vendor/', '/node_modules/', '/storage/', '/bootstrap/cache/'] as $excluded) {
            if (str_contains($pathname, $excluded)) {
                return false;
            }
        }

        return true;
    }
}
