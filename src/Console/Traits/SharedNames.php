<?php

namespace HZ\Illuminate\Mongez\Console\Traits;

trait SharedNames
{
    /**
     * Get proper repository name
     */
    protected function repositoryName(string $repositoryName): string
    {
        return $this->plural(
            $this->camel($repositoryName)
        );
    }

    /**
     * Get proper filter class name
     */
    protected function filterClass(string $filterClass): string
    {
        return  $this->plural(
            str_replace('Filter', '', $this->studly($filterClass))
        ) . 'Filter';
    }

    /**
     * Get proper resource class name
     */
    protected function resourceClass(string $resourceClass): string
    {
        return  $this->singular($this->studly(
            str_replace('Resource', '', $resourceClass)
        )) . 'Resource';
    }

    /**
     * Get Proper Model Class name
     */
    protected function modelClass(string $modelClass): string
    {
        return $this->singular($this->studly($modelClass));
    }
}
