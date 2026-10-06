<?php

namespace HZ\Illuminate\Mongez\Support;

use stdClass;

/**
 * Deep `id` -> `nid` key renamer backing the rename phase of `mongez:migrate-nid`.
 *
 * Mirrors `transform()` / `hasIdKey()` in scripts/mongo-nid-migration.js: every
 * key named `id` at any nesting or array level becomes `nid`, except `_id` and
 * any path whose segments match `skipPaths` — opaque third-party payloads such
 * as `providerResponse.id` are not business keys and must survive untouched.
 *
 * Documents MUST be decoded with the type map in `NidKeyRenamer::TYPE_MAP`.
 * Decoding sub-documents as `stdClass` (rather than as PHP arrays) is what keeps
 * an empty BSON `{}` from being re-encoded as `[]`; PHP lists stay BSON arrays.
 * Re-encoding the result is then faithful for every other BSON type — ObjectId,
 * Int64, UTCDateTime, Binary, Decimal128, Regex, Min, Max — so a `replaceOne()`
 * only changes the keys it was asked to change.
 */
final class NidKeyRenamer
{
    /**
     * The only type map under which {@see rename()} is safe to write back.
     *
     * @var array{root: string, document: string, array: string}
     */
    public const TYPE_MAP = ['root' => 'array', 'document' => 'object', 'array' => 'array'];

    /**
     * Path segments that opt a nested `id` key out of the rename.
     *
     * @var list<string>
     */
    private array $skipPaths;

    /**
     * @param  list<string>  $skipPaths  Dotted path segments, e.g. `['providerResponse', 'gatewayResponse']`.
     */
    public function __construct(array $skipPaths = [])
    {
        $cleaned = [];

        foreach ($skipPaths as $path) {
            $path = trim((string) $path);

            if ($path !== '') {
                $cleaned[] = $path;
            }
        }

        $this->skipPaths = array_values(array_unique($cleaned));
    }

    /**
     * Return a copy of the document with every renamable `id` key turned into `nid`.
     *
     * A document that already carries `nid` alongside `id` is treated as
     * migrated: the existing `nid` wins and the stale `id` is dropped, which is
     * what makes re-running the migration idempotent.
     *
     * @param  array<string, mixed>  $document  Decoded with {@see TYPE_MAP}.
     * @return array{document: array<string, mixed>, renamedKeys: int, droppedKeys: int, paths: list<string>}
     */
    public function rename(array $document): array
    {
        $renamed = 0;
        $dropped = 0;
        /** @var array<string, true> $paths */
        $paths = [];

        // The root is always a document, even though nested lists are PHP arrays.
        $result = $this->walkFields($document, '', $renamed, $dropped, $paths);

        $names = array_keys($paths);
        sort($names);

        return [
            'document' => $result,
            'renamedKeys' => $renamed,
            'droppedKeys' => $dropped,
            'paths' => $names,
        ];
    }

    /**
     * Report whether any renamable `id` key survives anywhere in the value.
     *
     * Honours the same `skipPaths` list as {@see rename()}, so a collection that
     * only keeps opaque `providerResponse.id` keys verifies as clean.
     */
    public function containsIdKey(mixed $value, string $path = ''): bool
    {
        if ($value instanceof stdClass) {
            return $this->fieldsContainIdKey((array) $value, $path);
        }

        if (! is_array($value)) {
            return false;
        }

        // BSON arrays decode to PHP lists; anything else is a document in disguise.
        if (! array_is_list($value)) {
            return $this->fieldsContainIdKey($value, $path);
        }

        $childPath = $path === '' ? '[]' : $path . '[]';

        foreach ($value as $child) {
            if ($this->containsIdKey($child, $childPath)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, true>  $paths
     */
    private function walk(mixed $value, string $path, int &$renamed, int &$dropped, array &$paths): mixed
    {
        if ($value instanceof stdClass) {
            return $this->asDocument($this->walkFields((array) $value, $path, $renamed, $dropped, $paths));
        }

        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            return $this->asDocument($this->walkFields($value, $path, $renamed, $dropped, $paths));
        }

        $out = [];
        $childPath = $path === '' ? '[]' : $path . '[]';

        foreach ($value as $child) {
            $out[] = $this->walk($child, $childPath, $renamed, $dropped, $paths);
        }

        return $out;
    }

    /**
     * Keep a BSON sub-document distinguishable from a BSON array on re-encode.
     *
     * A non-empty map of string keys re-encodes as a document either way, so it
     * is returned as a plain array for readability. The two cases where that
     * assumption breaks — no fields at all, or fields that look like a list —
     * keep the stdClass wrapper.
     *
     * @param  array<array-key, mixed>  $fields
     * @return array<array-key, mixed>|stdClass
     */
    private function asDocument(array $fields): array|stdClass
    {
        return $fields === [] || array_is_list($fields) ? (object) $fields : $fields;
    }

    /**
     * @param  array<array-key, mixed>  $fields
     * @param  array<string, true>  $paths
     * @return array<array-key, mixed>
     */
    private function walkFields(array $fields, string $path, int &$renamed, int &$dropped, array &$paths): array
    {
        $out = [];

        foreach ($fields as $key => $child) {
            $childPath = $path === '' ? (string) $key : $path . '.' . $key;

            if ($key === 'id' && ! $this->isSkipped($childPath)) {
                $paths[$childPath] = true;

                if (array_key_exists('nid', $fields)) {
                    $dropped++;

                    continue;
                }

                $renamed++;
                $out['nid'] = $this->walk($child, $this->nidPath($childPath), $renamed, $dropped, $paths);

                continue;
            }

            $out[$key] = $this->walk($child, $childPath, $renamed, $dropped, $paths);
        }

        return $out;
    }

    // ── Reverse: nid -> id (for rollback / new->old sync) ─────────────────

    /**
     * Mirror of {@see rename()} but `nid` -> `id`.
     *
     * Used by the `mongez:nid-sync --direction=reverse` rollback path.
     * Same deep walk, same `skipPaths` handling, same idempotency rule:
     * if a document already carries `id` alongside `nid`, `id` wins and the
     * stale `nid` is dropped.
     *
     * @param  array<string, mixed>  $document  Decoded with {@see TYPE_MAP}.
     * @return array{document: array<string, mixed>, renamedKeys: int, droppedKeys: int, paths: list<string>}
     */
    public function revert(array $document): array
    {
        $renamed = 0;
        $dropped = 0;
        /** @var array<string, true> $paths */
        $paths = [];

        $result = $this->walkFieldsReverse($document, '', $renamed, $dropped, $paths);

        $names = array_keys($paths);
        sort($names);

        return [
            'document' => $result,
            'renamedKeys' => $renamed,
            'droppedKeys' => $dropped,
            'paths' => $names,
        ];
    }

    /**
     * Mirror of {@see containsIdKey()} for `nid` keys.
     */
    public function containsNidKey(mixed $value, string $path = ''): bool
    {
        if ($value instanceof stdClass) {
            return $this->fieldsContainNidKey((array) $value, $path);
        }

        if (! is_array($value)) {
            return false;
        }

        if (! array_is_list($value)) {
            return $this->fieldsContainNidKey($value, $path);
        }

        $childPath = $path === '' ? '[]' : $path . '[]';

        foreach ($value as $child) {
            if ($this->containsNidKey($child, $childPath)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Shallow `id` -> `nid` for `--top-level-only` syncs.
     *
     * @param  array<string, mixed>  $document
     * @return array{document: array<string, mixed>, renamedKeys: int, droppedKeys: int}
     */
    public function renameTopLevelDoc(array $document): array
    {
        if (! array_key_exists('id', $document)) {
            return ['document' => $document, 'renamedKeys' => 0, 'droppedKeys' => 0];
        }

        if (array_key_exists('nid', $document)) {
            unset($document['id']);

            return ['document' => $document, 'renamedKeys' => 0, 'droppedKeys' => 1];
        }

        $document['nid'] = $document['id'];
        unset($document['id']);

        return ['document' => $document, 'renamedKeys' => 1, 'droppedKeys' => 0];
    }

    /**
     * Shallow `nid` -> `id` for `--top-level-only` reverse syncs.
     *
     * @param  array<string, mixed>  $document
     * @return array{document: array<string, mixed>, renamedKeys: int, droppedKeys: int}
     */
    public function revertTopLevelDoc(array $document): array
    {
        if (! array_key_exists('nid', $document)) {
            return ['document' => $document, 'renamedKeys' => 0, 'droppedKeys' => 0];
        }

        if (array_key_exists('id', $document)) {
            unset($document['nid']);

            return ['document' => $document, 'renamedKeys' => 0, 'droppedKeys' => 1];
        }

        $document['id'] = $document['nid'];
        unset($document['nid']);

        return ['document' => $document, 'renamedKeys' => 1, 'droppedKeys' => 0];
    }

    /**
     * @param  array<string, true>  $paths
     */
    private function walkReverse(mixed $value, string $path, int &$renamed, int &$dropped, array &$paths): mixed
    {
        if ($value instanceof stdClass) {
            return $this->asDocument($this->walkFieldsReverse((array) $value, $path, $renamed, $dropped, $paths));
        }

        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            return $this->asDocument($this->walkFieldsReverse($value, $path, $renamed, $dropped, $paths));
        }

        $out = [];
        $childPath = $path === '' ? '[]' : $path . '[]';

        foreach ($value as $child) {
            $out[] = $this->walkReverse($child, $childPath, $renamed, $dropped, $paths);
        }

        return $out;
    }

    /**
     * @param  array<array-key, mixed>  $fields
     * @param  array<string, true>  $paths
     * @return array<array-key, mixed>
     */
    private function walkFieldsReverse(array $fields, string $path, int &$renamed, int &$dropped, array &$paths): array
    {
        $out = [];

        foreach ($fields as $key => $child) {
            $childPath = $path === '' ? (string) $key : $path . '.' . $key;

            if ($key === 'nid' && ! $this->isSkipped($childPath)) {
                $paths[$childPath] = true;

                if (array_key_exists('id', $fields)) {
                    $dropped++;

                    continue;
                }

                $renamed++;
                $out['id'] = $this->walkReverse($child, $this->idPath($childPath), $renamed, $dropped, $paths);

                continue;
            }

            $out[$key] = $this->walkReverse($child, $childPath, $renamed, $dropped, $paths);
        }

        return $out;
    }

    /**
     * @param  array<array-key, mixed>  $fields
     */
    private function fieldsContainIdKey(array $fields, string $path): bool
    {
        foreach ($fields as $key => $child) {
            $childPath = $path === '' ? (string) $key : $path . '.' . $key;

            if ($key === 'id' && ! $this->isSkipped($childPath)) {
                return true;
            }

            if ($this->containsIdKey($child, $childPath)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<array-key, mixed>  $fields
     */
    private function fieldsContainNidKey(array $fields, string $path): bool
    {
        foreach ($fields as $key => $child) {
            $childPath = $path === '' ? (string) $key : $path . '.' . $key;

            if ($key === 'nid' && ! $this->isSkipped($childPath)) {
                return true;
            }

            if ($this->containsNidKey($child, $childPath)) {
                return true;
            }
        }

        return false;
    }

    private function isSkipped(string $dottedPath): bool
    {
        if ($this->skipPaths === []) {
            return false;
        }

        $segments = explode('.', str_replace('[]', '', $dottedPath));

        foreach ($this->skipPaths as $skip) {
            if (in_array($skip, $segments, true)) {
                return true;
            }
        }

        return false;
    }

    private function nidPath(string $dottedPath): string
    {
        return str_ends_with($dottedPath, '.id')
            ? substr($dottedPath, 0, -3) . '.nid'
            : 'nid';
    }

    private function idPath(string $dottedPath): string
    {
        return str_ends_with($dottedPath, '.nid')
            ? substr($dottedPath, 0, -4) . '.id'
            : 'id';
    }
}
