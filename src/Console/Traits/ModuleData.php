<?php

namespace HZ\Illuminate\Mongez\Console\Traits;

use HZ\Illuminate\Mongez\Helpers\Mongez;
use HZ\Illuminate\Mongez\Console\Traits\TextUtils;

trait ModuleData
{
    /**
     * Text Helpers
     */
    use TextUtils;

    /**
     * Module Name
     */
    protected string $moduleName;

    /**
     * Repository Name
     */
    protected string $repositoryName;

    /**
     * Module Build mode
     * Available Values api|ui
     */
    protected string $buildMode;

    /**
     * Prepare data
     * 
     * @return void
     */
    protected function prepareData()
    {
        $this->buildMode = $this->optionHasValue('build') ? $this->option('value') : $this->config('build', 'api');
    }

    /**
     * Determine if the current build mode is api mode
     */
    protected function isApiMode(): bool
    {
        return $this->buildMode === 'api';
    }

    /**
     * Determine if the current build mode is ui mode
     */
    protected function isUINode(): bool
    {
        return $this->buildMode === 'ui';
    }

    /**
     * Set Module Name
     *
     * @return void
     */
    protected function setModuleName(string $moduleName)
    {
        $this->moduleName = $moduleName;
    }

    /**
     * Get module name
     * The module name MUST BE in plural with studly case format
     */
    protected function getModule(): string
    {
        return $this->plural(
            $this->studly($this->moduleName)
        );
    }

    /**
     * Get singular module studly case text
     */
    protected function singularModule(): string
    {
        return $this->singular($this->getModule());
    }

    /**
     * Return a full data types options with the given options
     * If second parameter is not empty, then its value will be taken as well from
     * the passed options list
     */
    protected function withDataTypes(array $options, array $moreOptions = []): array
    {
        $optionsList = $this->optionsValues(array_merge(
            static::DATA_TYPES,
            $moreOptions,
        ));

        return array_merge($options, $optionsList);
    }

    /**
     * Determine if the given module name exists in modules list
     */
    protected function moduleExists(string $moduleName = ''): bool
    {
        return in_array(
            strtolower($moduleName ?: $this->getModule()),
            array_map(strtolower(...), Mongez::getStored('modules'))
        );
    }
}
