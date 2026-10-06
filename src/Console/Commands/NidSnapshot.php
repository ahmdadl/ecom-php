<?php

namespace HZ\Illuminate\Mongez\Console\Commands;

use HZ\Illuminate\Mongez\Database\Eloquent\MongoDB\Database;
use Illuminate\Console\Command;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Client;
use MongoDB\Database as MongoDatabase;

/**
 * Read-only manifest of the current database at the moment the production
 * clone is taken.
 *
 * Stores per-collection watermarks (`count`, `max(id|nid)`, `max(_id)`,
 * `max(updatedAt)`) plus a dump of the `ids` counters. The manifest is the
 * input to `mongez:nid-sync --manifest=...` and never writes to the database.
 */
class NidSnapshot extends Command
{
    protected const SKIP_EXACT = [
        'ids',
        'migrations',
        'sessions',
        'jobs',
        'failed_jobs',
        'job_batches',
        'queue_records',
        'cache',
        'cache_locks',
        'personal_access_tokens',
        'password_resets',
    ];

    protected const SKIP_PREFIXES = ['oauth_', 'system.'];

    protected $signature = 'mongez:nid-snapshot
        {--uri= : MongoDB URI of the database to snapshot (defaults to the app mongodb connection)}
        {--out= : Path to write the manifest JSON (default: storage/app/nid-sync/manifest-{timestamp}.json)}
        {--collection=* : Limit snapshot to the given collection names}
        {--include-trash : Also include *Trash collections}';

    protected $description = 'Capture a nid-sync manifest: per-collection counts, max(id/nid), max(_id), max(updatedAt) and ids counters';

    public function handle(): int
    {
        $uriRaw = $this->option('uri');
        $uri = is_string($uriRaw) ? $uriRaw : '';
        $database = $this->resolveDatabase($uri);
        $targets = $this->targetCollections($database);

        $this->line(sprintf('DB: %s | collections: %d', $database->getDatabaseName(), count($targets)));

        $generatedAt = new UTCDateTime((int) (microtime(true) * 1000));
        $collections = [];

        foreach ($targets as $name) {
            $collection = $database->selectCollection($name);

            $count = $collection->countDocuments([]);

            [$maxId, $maxNid] = $this->maxIds($collection);
            $maxObjectId = $this->maxObjectId($collection);
            $maxUpdatedAt = $this->maxUpdatedAt($collection);

            $collections[$name] = [
                'count' => $count,
                'maxId' => $maxId,
                'maxNid' => $maxNid,
                'maxObjectId' => $maxObjectId,
                'maxUpdatedAt' => $maxUpdatedAt ? $this->utcToIso($maxUpdatedAt) : null,
                'maxUpdatedAtMs' => $maxUpdatedAt ? (int) (string) $maxUpdatedAt : null,
            ];

            $this->line(sprintf(
                '  %s: count=%d maxId=%d maxNid=%d maxObjectId=%s maxUpdatedAt=%s',
                $name,
                $count,
                $maxId,
                $maxNid,
                $maxObjectId ?? 'null',
                $collections[$name]['maxUpdatedAt'] ?? 'null',
            ));
        }

        $idsRows = $this->dumpIds($database);

        $uriRaw2 = $this->option('uri');
        $uriForManifest = is_string($uriRaw2) ? $uriRaw2 : '';
        $manifest = [
            'generatedAt' => $this->utcToIso($generatedAt),
            'generatedAtMs' => (int) (string) $generatedAt,
            'database' => $database->getDatabaseName(),
            'uri' => $this->sanitizeUri($uriForManifest),
            'collections' => $collections,
            'ids' => $idsRows,
        ];

        $outRaw = $this->option('out');
        $out = is_string($outRaw) ? $outRaw : '';
        $outPath = $this->resolveOutPath($out);
        $dir = dirname($outPath);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            $this->error('Failed to encode manifest JSON.');

            return self::FAILURE;
        }

        file_put_contents($outPath, $json . "\n");

        $this->info("Manifest written to: {$outPath}");
        $this->line(sprintf('  generatedAt=%s database=%s idsRows=%d', $manifest['generatedAt'], $manifest['database'], count($idsRows)));

        return self::SUCCESS;
    }

    private function resolveDatabase(string $uri): MongoDatabase
    {
        $uri = trim($uri);

        if ($uri === '') {
            return Database::getDatabase();
        }

        $client = new Client($uri);
        $dbName = $this->extractDatabaseName($uri);

        if ($dbName !== null && $dbName !== '') {
            return $client->selectDatabase($dbName);
        }

        // URI without database path — fall back to the app's database name.
        $fallback = Database::getDatabase()->getDatabaseName();

        return $client->selectDatabase($fallback);
    }

    private function extractDatabaseName(string $uri): ?string
    {
        $parsed = parse_url($uri);

        if (! is_array($parsed) || ! isset($parsed['path'])) {
            return null;
        }

        $path = ltrim($parsed['path'], '/');

        if ($path === '') {
            return null;
        }

        // Strip query-like suffix after ? if parse_url put it in path.
        $path = explode('?', $path, 2)[0];
        $path = explode('/', $path, 2)[0];

        return $path !== '' ? $path : null;
    }

    private function sanitizeUri(string $uri): string
    {
        $uri = trim($uri);

        if ($uri === '') {
            return '<default connection>';
        }

        // Hide password if present: mongodb://user:pass@host/...
        return preg_replace('#(//[^:/@]+:)([^@]+)(@)#', '$1***$3', $uri) ?? $uri;
    }

    /**
     * @return list<string>
     */
    private function targetCollections(MongoDatabase $database): array
    {
        $selected = array_values(array_filter(array_map(
            static fn ($name): string => trim((string) $name),
            (array) $this->option('collection'),
        )));

        $names = $selected !== [] ? $selected : $this->listCollectionNames($database);

        $names = array_values(array_unique(array_filter($names, function (string $name): bool {
            if (in_array($name, self::SKIP_EXACT, true)) {
                return false;
            }

            foreach (self::SKIP_PREFIXES as $prefix) {
                if (str_starts_with($name, $prefix)) {
                    return false;
                }
            }

            return true;
        })));

        if ($this->option('include-trash')) {
            return $names;
        }

        return array_values(array_filter($names, static fn (string $name): bool => ! str_ends_with($name, 'Trash')));
    }

    /**
     * @return list<string>
     */
    private function listCollectionNames(MongoDatabase $database): array
    {
        $names = [];

        foreach ($database->listCollections() as $info) {
            $names[] = $info->getName();
        }

        return $names;
    }

    /**
     * @return array{int,int} [maxId, maxNid]
     */
    private function maxIds(\MongoDB\Collection $collection): array
    {
        $result = $collection->aggregate([
            ['$group' => ['_id' => null, 'maxId' => ['$max' => '$id'], 'maxNid' => ['$max' => '$nid']]],
        ])->toArray();

        if ($result === []) {
            return [0, 0];
        }

        $maxId = $result[0]->maxId ?? null;
        $maxNid = $result[0]->maxNid ?? null;

        return [
            is_numeric($maxId) ? (int) $maxId : 0,
            is_numeric($maxNid) ? (int) $maxNid : 0,
        ];
    }

    private function maxObjectId(\MongoDB\Collection $collection): ?string
    {
        $doc = $collection->findOne([], ['sort' => ['_id' => -1], 'projection' => ['_id' => 1]]);

        if ($doc === null) {
            return null;
        }

        $id = null;

        if (is_array($doc)) {
            $id = $doc['_id'] ?? null;
        } else {
            $id = $doc->_id ?? null;
            if ($id === null && method_exists($doc, 'toPHP')) {
                $php = $doc->toPHP();
                $id = is_array($php) ? ($php['_id'] ?? null) : null;
            }
        }

        // Fallback: fetch as BSON document then get
        if ($id === null) {
            $raw = $collection->findOne([], ['sort' => ['_id' => -1], 'typeMap' => ['root' => 'array', 'document' => 'array']]);
            $id = is_array($raw) ? ($raw['_id'] ?? null) : null;
        }

        if ($id instanceof ObjectId) {
            return (string) $id;
        }

        return null;
    }

    private function maxUpdatedAt(\MongoDB\Collection $collection): ?UTCDateTime
    {
        try {
            $result = $collection->aggregate([
                ['$group' => ['_id' => null, 'max' => ['$max' => '$updatedAt']]],
            ], ['typeMap' => ['root' => 'array', 'document' => 'array']])->toArray();
        } catch (\Throwable) {
            return null;
        }

        if ($result === [] || ! isset($result[0]['max'])) {
            return null;
        }

        $max = $result[0]['max'];

        if ($max instanceof UTCDateTime) {
            // Zero value means no dated documents
            if ((int) (string) $max === 0) {
                return null;
            }

            return $max;
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function dumpIds(MongoDatabase $database): array
    {
        $rows = [];

        foreach ($database->selectCollection('ids')->find([], ['typeMap' => ['root' => 'array', 'document' => 'array']]) as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private function utcToIso(UTCDateTime $utc): string
    {
        $dt = $utc->toDateTime();

        return $dt->format('Y-m-d\TH:i:s.v\Z');
    }

    private function resolveOutPath(string $out): string
    {
        $out = trim($out);

        if ($out !== '') {
            return $out;
        }

        $stamp = date('Ymd-His');

        return storage_path("app/nid-sync/manifest-{$stamp}.json");
    }
}
