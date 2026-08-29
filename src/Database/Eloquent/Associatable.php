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

        if ($modelInfo instanceof Model) {
            $modelInfo = $modelInfo->sharedInfo();
        }

        $found = false;

        foreach ($documents as $key => $document) {
            if ($document === $modelInfo) {
                $documents[$key] = $modelInfo;
                $found = true;

                break;
            } else {
                $document = (array) $document;
                if (isset($document[$searchingColumn]) && $document[$searchingColumn] == $modelInfo[$searchingColumn]) {
                    $documents[$key] = $modelInfo;
                    $found = true;

                    break;
                }
            }
        }

        if (!$found) {
            $documents[] = $modelInfo;
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

        $newArray = [];

        foreach ($array as $value) {
            if (
                is_array($value) && isset($value[$searchBy]) && $value[$searchBy] == $modelInfo[$searchBy]
            ) {
                continue;
            }

            $newArray[] = $value;
        }

        $this->setAttribute($column, $newArray);

        return $this;
    }
}
