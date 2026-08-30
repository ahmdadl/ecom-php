<?php

namespace HZ\Illuminate\Mongez\Console\Traits;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use HZ\Illuminate\Mongez\Helpers\Mongez;

/**
 * @property array<string, mixed> $info
 * @property string $moduleName
 * @method bool confirm(string $message)
 * @method void error(string $message)
 * @method string getModule()
 * @method mixed option(string $key = null)
 * @method mixed argument(string $key = null)
 * @method bool optionHasValue(string $option)
 * @method bool argumentHasValue(string $argument)
 */
trait EngezTrait
{
    /**
     * File manager instance (provided by the using command class).
     */
    protected Filesystem $files;

    /**
     * Repository class short name (provided by the using command class).
     */
    protected string $repositoryClassName;

    /**
     * Repository name (provided by the using command class).
     */
    protected string $repositoryName;

    /**
     * Get the given console option value as a string.
     *
     * The base Command::option() return type includes array, but options in
     * this package are always strings, so we narrow it here safely.
     */
    protected function stringOption(string $key): string
    {
        $value = $this->option($key);

        return is_array($value) ? '' : (string) $value;
    }

    /**
     * Get the given console argument value as a string.
     */
    protected function stringArgument(string $key): string
    {
        $value = $this->argument($key);

        return is_array($value) ? '' : (string) $value;
    }

    /**
     * Create the file
     * 
     * @param  string $filePath
     * @param  string $content
     * @param  string $fileType
     * @return void
     */
    protected function createFile($filePath, $content, $fileType)
    {
        $filePath = str_replace('\\', '/', $filePath);

        $createFile = true;
        if ($this->files->exists($filePath)) {
            $createFile = $this->confirm($fileType . ' exists, override it?');
        }

        if ($createFile) {
            $this->files->put($filePath, $content);
        }
    }

    /**
     * Get relative path to base path
     * 
     * @param  string $path
     * @return string 
     */
    protected function path($path)
    {
        return Mongez::packagePath('/module/' . $path);
    }

    /**
     * Get the final path of the module for the given relative path
     */
    protected function modulePath(string $relativePath): string
    {
        $targetModule = $this->getModule();

        if (isset($this->info['parent'])) {
            $targetModule = $this->info['parent'];
        }

        return base_path("app/Modules/{$targetModule}/$relativePath");
    }

    /**
     * Check if the given directory path is not created, if so then create one
     *
     * @return  void
     */
    public function makeDirectory(string $directoryPath)
    {
        $directoryPath = str_replace('\\', '/', $directoryPath);

        if (!$this->files->isDirectory($directoryPath)) {
            $this->files->makeDirectory($directoryPath, 0755, true, true);
        }
    }

    /**
     * Output Missing Required options to console
     * 
     * @param  string $message
     * @return void 
     */
    public function missingRequiredOption($message)
    {
        $this->terminate($message);
    }

    /**
     * Display the given error message then terminate the request
     */
    protected function terminate(string $message): never
    {
        $this->error($message);
        die();
    }

    /**
     * Get a repository shortcut name based on the given module name
     */
    public function repositoryShortcutName(string $module): string
    {
        return Str::plural(Str::camel($module));
    }

    /**
     * Set module name to config file.
     * 
     * @return void
     */
    protected function addModule()
    {
        Mongez::append('modules', $this->getModule());
    }

    /**
     * Remove module name from config file.
     * 
     * @return void
     */
    protected function unsetModuleNameFromMongez()
    {
        Mongez::remove('modules', $this->getModule());
    }

    /**
     * Update module name to config file.
     * 
     * @return void
     */
    protected function markModuleAsInstalled()
    {
        Mongez::updateStorageFile();
    }

    /**
     * Get options values for the given array
     *
     * @param array<int, string> $keys
     * @return array<string, mixed>
     */
    protected function optionsValues(array $keys): array
    {
        $neededOptions = [];

        foreach ($keys as $index => $key) {
            if (is_numeric($index)) {
                $index = $key;
            }

            if (!Str::startsWith($index, '--')) {
                $index = '--' . $index;
            }

            if ($this->optionHasValue($key)) {
                $neededOptions[$index] = $this->option($key);
            }
        }

        return $neededOptions;
    }

    /**
     * Add routes to permission table
     * 
     * @return void 
     */
    public function addRoutesToPermissionTable()
    {
        try {
            $permissionsRepo = repo('permissions');
            // @phpstan-ignore-next-line method.notFound
            $permissionsRepo->insertModulePermissions($this->moduleName);
        } catch (\Throwable) {
            // this wil silent the not found repository if there is no permissions repository 
        }
    }

    /**
     * Update configurations
     */
    protected function updateConfig(): void
    {
        $config = $this->files->get($mongezPath =  base_path('config/mongez.php'));

        $replacementLine = '// Auto generated repositories here: DO NOT remove this line.';

        if (!Str::contains($config, $replacementLine)) return;

        $repositoryClassName = $this->repositoryClassName;

        $repositoryShortcut = $this->repositoryName;

        $module = $this->getModule();

        $replacedString = "'{$repositoryShortcut}' => App\\Modules\\$module\\Repositories\\{$repositoryClassName}::class,\n \t\t $replacementLine";

        $updatedConfig = str_replace($replacementLine, $replacedString, $config);

        config(['mongez.repositories.' . $repositoryShortcut => "App\\Modules\\$module\\Repositories\\{$repositoryClassName}"]);

        $this->files->put($mongezPath, $updatedConfig);
    }

    /**
     * Update configurations
     */
    protected function updateServiceProviderConfig(): void
    {
        $config = $this->files->get($mongezPath = base_path('config/app.php'));

        $replacementLine = '// Auto generated providers here: DO NOT remove this line.';

        if (!Str::contains($config, $replacementLine)) return;

        $module = $this->getModule();

        $serviceProviderClassName = $module . 'ServiceProvider';

        $replacedString = "App\\Modules\\$module\\Providers\\{$serviceProviderClassName}::class,\n \t\t$replacementLine";
        $updatedConfig = str_replace($replacementLine, $replacedString, $config);

        $this->files->put($mongezPath, $updatedConfig);
    }

    /**
     * Remove module service provider path
     * 
     * @param $moduleName
     * @return void
     */
    protected function unsetModuleServiceProvider()
    {
        $config = $this->files->get($mongezPath =  base_path('config/app.php'));

        $serviceProviderClassName = Str::singular($this->moduleName) . 'ServiceProvider';

        $replacementLine = "App\\Modules\\$this->moduleName\\Providers\\{$serviceProviderClassName}::class,";

        if (!Str::contains($config, $replacementLine)) return;

        $replacedString = "";

        $updatedConfig = str_replace($replacementLine, $replacedString, $config);

        $this->files->put($mongezPath, $updatedConfig);
    }
}
