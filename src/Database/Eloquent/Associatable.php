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
}
