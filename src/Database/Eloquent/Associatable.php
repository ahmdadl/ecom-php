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

        if ($modelInfo instanceof Model) {
            $listOfValues[] = $modelInfo->sharedInfo();
        } else {
            $listOfValues[] = $modelInfo;
        }

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

        $modelInfoArray = $modelInfo instanceof Model ? $modelInfo->sharedInfo() : (array) $modelInfo;
        $searchValue = $modelInfoArray[$searchingColumn] ?? null;

        $found = false;

        foreach ($documents as $key => $document) {
            $documentArray = (array) $document;

            if (isset($documentArray[$searchingColumn]) && $documentArray[$searchingColumn] == $searchValue) {
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

        $modelInfoArray = $modelInfo instanceof Model ? $modelInfo->sharedInfo() : (array) $modelInfo;
        $searchValue = $modelInfoArray[$searchBy] ?? null;

        $newArray = [];

        foreach ($array as $value) {
            $valueArray = (array) $value;

            if (isset($valueArray[$searchBy]) && $valueArray[$searchBy] == $searchValue) {
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

        if (is_array($current) && $current !== [] && array_is_list($current)) {
            $documents = $current;
            $searchValue = $snapshot[$searchingColumn] ?? $related->{$searchingColumn} ?? null;
            $found = false;

            foreach ($documents as $key => $document) {
                $documentArray = (array) $document;

                if (isset($documentArray[$searchingColumn]) && $documentArray[$searchingColumn] == $searchValue) {
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
            if (! array_key_exists($key, $document) || $document[$key] != $expected) {
                return false;
            }
        }

        return true;
    }
}
