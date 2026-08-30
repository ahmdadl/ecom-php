<?php

namespace HZ\Illuminate\Mongez\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use HZ\Illuminate\Mongez\Helpers\Mongez;

/**
 * @method bool argumentHasValue(string $argument)
 */
class EngezMigrate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'engez:migrate {modules?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run the database migrations to modules';

    /**
     * The module name
     *
     * @var array<int, string>
     */
    protected $availableModules = [];

    /**
     * The module path
     *
     * @var array<int, string>
     */
    protected $paths = [];

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        if ($this->argumentHasValue('modules')) {
            $this->availableModules = explode(',', $this->stringArgument('modules'));
        } else {
            $this->paths[] = Mongez::packagePath('src/Database/migrations/' . config('database.default'));
            $this->availableModules = Mongez::getStored('modules');
        }

        $this->generateModulesPaths();

        $this->makeMigrate();
    }

    /**
     * {@inheritDoc}
     */
    public function init(): void
    {
    }

    /**
     * Make migration file for module
     *
     * @return void
     */
    protected function makeMigrate()
    {
        Artisan::call('migrate', ['--path' => $this->paths, '--realpath' => true]);

        $this->info('Migrate tables has been created Successfully ');
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
     * Generate Module path 
     * 
     * @return void
     */
    protected function generateModulesPaths()
    {
        foreach ($this->availableModules as $moduleName) {
            $this->paths[] = app_path("Modules/{$moduleName}/Database/migrations");
        }
    }
}
