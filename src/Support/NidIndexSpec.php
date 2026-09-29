<?php

namespace HZ\Illuminate\Mongez\Support;

/**
 * One index the `nid` migration has to create, verify, and name consistently.
 *
 * Mirrors an entry of `nidIndexes` / `additionalIndexes` in
 * scripts/mongo-nid-migration.js. The name is always resolved up front — either
 * given explicitly or derived from the key spec the same way the JS script
 * derives it — so the create and verify passes cannot disagree.
 */
final class NidIndexSpec
{
    /**
     * @param  array<string, int>  $keys
     * @param  array<string, mixed>  $extra  Any further createIndex options (sparse, partialFilterExpression, ...).
     */
    private function __construct(
        public readonly array $keys,
        public readonly string $name,
        public readonly bool $unique,
        public readonly array $extra = [],
    ) {}

    /**
     * @param  array<string, mixed>  $spec  `keys` plus optional `name`, `unique`, and any createIndex option.
     */
    public static function fromArray(array $spec): self
    {
        $keys = [];

        /** @var mixed $direction */
        foreach ($spec['keys'] ?? [] as $field => $direction) {
            $keys[(string) $field] = (int) $direction;
        }

        $name = is_string($spec['name'] ?? null) && $spec['name'] !== ''
            ? $spec['name']
            : self::defaultName($keys);

        $extra = $spec;
        unset($extra['keys'], $extra['name'], $extra['unique']);

        return new self($keys, $name, (bool) ($spec['unique'] ?? true), $extra);
    }

    /**
     * MongoDB-style derived name: each `{field}_{direction}` in key order,
     * joined with `_` — the same shape MongoDB itself would generate.
     *
     * `['nid' => 1]` becomes `nid_1`; `['installationTeam.nid' => 1, 'city.nid' => 1]`
     * becomes `installationTeam.nid_1_city.nid_1`.
     *
     * @param  array<string, int>  $keys
     */
    public static function defaultName(array $keys): string
    {
        $parts = [];

        foreach ($keys as $field => $direction) {
            $parts[] = $field . '_' . $direction;
        }

        return implode('_', $parts);
    }

    /**
     * Whether this index is the unique `nid` index, i.e. the one that duplicate
     * `nid` values would block.
     *
     * Only the exact `{nid: 1}` unique index qualifies — a composite index like
     * `{nid: 1, other: 1}` allows duplicate `nid` values as long as the
     * combination is unique, so it must not gate the migration on `nid`
     * duplicates.
     */
    public function isUniqueNidIndex(): bool
    {
        return $this->unique
            && $this->keys === ['nid' => 1];
    }

    /**
     * @return array<string, mixed>
     */
    public function createOptions(): array
    {
        return array_merge($this->extra, [
            'name' => $this->name,
            'unique' => $this->unique,
        ]);
    }
}
