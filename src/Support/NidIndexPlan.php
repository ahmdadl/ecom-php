<?php

namespace HZ\Illuminate\Mongez\Support;

/**
 * Per-collection index plan resolved from the `mongez.nid.indexes` config.
 *
 * Mirrors `MIGRATION_INDEX_CONFIG` / `getMigrationIndexInfo()` in
 * scripts/mongo-nid-migration.js: an unlisted collection gets a unique `nid_1`
 * and nothing else, a listed collection gets exactly what it declares, and an
 * explicitly empty `nid` list is the way to opt a collection out of `nid_1`
 * entirely (the JS `courier` case).
 */
final class NidIndexPlan
{
    /**
     * The index MongoDB creates for a legacy `id` key — always dropped.
     */
    public const LEGACY_INDEX = 'id_1';

    /**
     * @param  list<NidIndexSpec>  $nidIndexes  Must exist after the migration; includes the unique `nid_1` unless opted out.
     * @param  list<NidIndexSpec>  $additionalIndexes  Non-identity indexes the app needs alongside the cutover.
     * @param  list<string>  $dropIndexes  Pre-cutover index names to remove.
     */
    private function __construct(
        public readonly array $nidIndexes,
        public readonly array $additionalIndexes,
        public readonly array $dropIndexes,
    ) {}

    /**
     * @param  array<string, array{nid?: array<int, array<string, mixed>>, additional?: array<int, array<string, mixed>>, drop?: array<int, string>}>  $config
     */
    public static function resolve(string $collection, array $config, bool $defaultNidIndex = true): self
    {
        $entry = $config[$collection] ?? [];

        $nidIndexes = self::specs($entry['nid'] ?? []);

        // An explicitly empty `nid` list is the documented way to opt a
        // collection out of the nid index, so only a *missing* key falls back
        // to the default.
        if (! array_key_exists('nid', $entry) && $defaultNidIndex) {
            $nidIndexes = [NidIndexSpec::fromArray(['keys' => ['nid' => 1], 'unique' => true])];
        }

        $drop = $entry['drop'] ?? [];
        $dropNames = array_merge([self::LEGACY_INDEX], array_map(static fn ($name): string => (string) $name, $drop));

        return new self(
            $nidIndexes,
            self::specs($entry['additional'] ?? []),
            array_values(array_unique($dropNames)),
        );
    }

    /**
     * Every index this plan requires to exist once the migration has run.
     *
     * @return list<NidIndexSpec>
     */
    public function required(): array
    {
        return array_merge($this->nidIndexes, $this->additionalIndexes);
    }

    /**
     * @param  mixed  $specs
     * @return list<NidIndexSpec>
     */
    private static function specs(mixed $specs): array
    {
        if (! is_array($specs)) {
            return [];
        }

        $resolved = [];

        /** @var mixed $spec */
        foreach (array_values($specs) as $spec) {
            if (is_array($spec)) {
                $resolved[] = NidIndexSpec::fromArray($spec);
            }
        }

        return $resolved;
    }
}
