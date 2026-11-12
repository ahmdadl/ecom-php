<?php

namespace HZ\Illuminate\Mongez\Database\Eloquent;

use HZ\Illuminate\Mongez\Database\Eloquent\MongoDB\Model;

trait Associatable
{
    /**
     * Associate the given value to the given key
     *
     * @param mixed $modelInfo
     */
    public function associate($modelInfo, string $column): Model
    {
        $listOfValues = $this->{$column} ?? [];

        if (!is_array($listOfValues)) {
            $listOfValues = $listOfValues === '' ? [] : [$listOfValues];
        }

        $infoArray = $modelInfo instanceof Model
            ? $modelInfo->sharedInfo()
            : (is_array($modelInfo) ? $modelInfo : ['nid' => $modelInfo]);

        $searchValue = $infoArray['nid'] ?? null;

        if ($searchValue !== null) {
            foreach ($listOfValues as $existing) {
                $existingArray = (array) $existing;

                if (array_key_exists('nid', $existingArray) && (string) $existingArray['nid'] === (string) $searchValue) {
                    return $this;
                }
            }
        }

        $listOfValues[] = $modelInfo instanceof Model ? $modelInfo->sharedInfo() : $modelInfo;

        $this->setAttribute($column, $listOfValues);

        return $this;
    }

    /**
     * Re-associate the given document
     *
     * @param   mixed $modelInfo
     */
    public function reassociate($modelInfo, string $column, string $searchingColumn = 'nid'): Model
    {
        $documents = $this->{$column} ?? [];

        if (!is_array($documents)) {
            $documents = [$documents];
        }

        $modelInfoArray = $modelInfo instanceof Model ? $modelInfo->sharedInfo() : (array) $modelInfo;
        $searchValue = $modelInfoArray[$searchingColumn] ?? null;

        $found = false;

        foreach ($documents as $key => $document) {
            $documentArray = (array) $document;

            if ($searchValue !== null && array_key_exists($searchingColumn, $documentArray) && (string) $documentArray[$searchingColumn] === (string) $searchValue) {
                $documents[$key] = $modelInfoArray;
                $found = true;

                break;
            }
        }

        if (!$found) {
            $documents[] = $modelInfoArray;
        }

        $this->setAttribute($column, $documents);

        return $this;
    }

    /**
     * Disassociate the given value to the given key
     *
     * @param mixed $modelInfo
     */
    public function disassociate($modelInfo, string $column, string $searchBy = 'nid'): Model
    {
        $array = $this->{$column} ?? [];

        if (!is_array($array)) {
            $array = [$array];
        }

        $modelInfoArray = $modelInfo instanceof Model ? $modelInfo->sharedInfo() : (array) $modelInfo;
        $searchValue = $modelInfoArray[$searchBy] ?? null;

        $newArray = [];

        foreach ($array as $value) {
            $valueArray = (array) $value;

            if ($searchValue !== null && array_key_exists($searchBy, $valueArray) && (string) $valueArray[$searchBy] === (string) $searchValue) {
                continue;
            }

            $newArray[] = $value;
        }

        $this->setAttribute($column, $newArray);

        return $this;
    }

    /**
     * Partially update one element in an embedded array without rewriting siblings.
     *
     * Matching:
     * - int: match element where `nid` equals the value
     * - array: match element where every given key equals the given value
     *
     * Uses in-document merge (safe for Octane / Eloquent save paths). Prefer Mongo
     * `arrayFilters` / positional updates only for mass query-builder writes outside
     * the model lifecycle — those must call cache invalidation manually.
     *
     * @param  string $path Embedded list attribute (e.g. `items`)
     * @param  int|array<string, mixed> $matchNidOrCriteria
     * @param  array<string, mixed> $data Merged into the matched element
     * @param  bool $createIfMissing Append `$data` (+ match keys) when nothing matches
     */
    public function patchEmbedded(
        string $path,
        int|array $matchNidOrCriteria,
        array $data,
        bool $createIfMissing = false
    ): Model {
        $documents = array_values((array) ($this->{$path} ?? []));
        $criteria = is_int($matchNidOrCriteria)
            ? ['nid' => $matchNidOrCriteria]
            : $matchNidOrCriteria;

        $found = false;

        foreach ($documents as $index => $document) {
            $documentArray = (array) $document;

            if (! $this->embeddedMatchesCriteria($documentArray, $criteria)) {
                continue;
            }

            $documents[$index] = array_merge($documentArray, $data);
            $found = true;
            break;
        }

        if (! $found && $createIfMissing) {
            $documents[] = array_merge($criteria, $data);
        }

        $this->setAttribute($path, $documents);

        return $this;
    }

    /**
     * Refresh an embedded sharedInfo snapshot from a related model.
     *
     * - List embeds (numeric array): reassociate matching element by `$searchingColumn`
     * - Singular embeds (assoc object / null): replace the whole attribute
     *
     * @param  string $path Attribute name on this model
     * @param  Model $related Related model providing the snapshot
     * @param  string $searchingColumn Match key for list embeds
     * @param  string $sharedInfoMethod Method on `$related` used to build the snapshot
     */
    public function refreshEmbeddedSharedInfo(
        string $path,
        Model $related,
        string $searchingColumn = 'nid',
        string $sharedInfoMethod = 'sharedInfo'
    ): Model {
        $current = $this->{$path};
        $snapshot = $related->{$sharedInfoMethod}();

        if (is_array($current) && ($current === [] || array_is_list($current))) {
            $documents = $current;
            $searchValue = $snapshot[$searchingColumn] ?? $related->{$searchingColumn} ?? null;
            $found = false;

            foreach ($documents as $key => $document) {
                $documentArray = (array) $document;

                if ($searchValue !== null && array_key_exists($searchingColumn, $documentArray) && (string) $documentArray[$searchingColumn] === (string) $searchValue) {
                    $documents[$key] = $snapshot;
                    $found = true;
                    break;
                }
            }

            if (! $found) {
                $documents[] = $snapshot;
            }

            $this->setAttribute($path, $documents);

            return $this;
        }

        $this->setAttribute($path, $snapshot);

        return $this;
    }

    /**
     * @param  array<string, mixed> $document
     * @param  array<string, mixed> $criteria
     */
    protected function embeddedMatchesCriteria(array $document, array $criteria): bool
    {
        if ($criteria === []) {
            return false;
        }

        foreach ($criteria as $key => $expected) {
            if (! array_key_exists($key, $document) || ! self::embeddedValuesEqual($document[$key], $expected)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Compare an embedded document value against match criteria.
     *
     * Scalars compare by string value so int/string nid mismatches still
     * match, while booleans keep strict identity.
     */
    protected static function embeddedValuesEqual(mixed $actual, mixed $expected): bool
    {
        if (is_bool($actual) || is_bool($expected)) {
            return $actual === $expected;
        }

        if ((is_scalar($actual) || $actual === null) && (is_scalar($expected) || $expected === null)) {
            return (string) $actual === (string) $expected;
        }

        return $actual === $expected;
    }
}
