<?php

namespace HZ\Illuminate\Mongez\Console\Commands;

use Illuminate\Support\Str;
use HZ\Illuminate\Mongez\Console\EngezInterface;
use HZ\Illuminate\Mongez\Console\EngezGeneratorCommand;

class ModuleDelete extends EngezGeneratorCommand implements EngezInterface
{
    /**
     * Module info
     *
     * @var array
     */
    protected $info = [];

    /**
     * Available Options
     *
     */
    protected $availableOptions = [];

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'engez:module-delete
                                       {moduleName}';
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete Module';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->setModuleName($this->argument('moduleName'));

        $this->init();

        $this->destroy();
    }

    /**
     * Init data
     *
     * @return void
     */
    public function init()
    {
        parent::init();

        $this->info('Deleting ...');
    }

    public function validateArguments()
    {
        $modulePath = $this->modulePath("");

        // if the module path exists and it has no parent, then terminate the command
        if ($this->files->isDirectory($modulePath) && !isset($this->info['parent'])) {
            $this->terminate('This module is already exist');
        }
    }

    /**
     * {@inheritDoc}
     */
    public function create()
    {
        // pass
    }

    /**
     * Destroy files
     *
     * @return void
     */
    public function destroy()
    {
        $this->removeModuleDirectory();

        $this->unsetModuleNameFromMongez();

        $this->unsetModuleServiceProvider();

        $this->unsetModuleRepository();

        $this->removeRepositoryHelper();

        $this->removeFromBaseSeedersClass();


        $this->markModuleAsInstalled();
    }

    /**
     * Delete module directory
     *
     * @return void
     */
    protected function removeModuleDirectory(): void
    {
        $modulePath = base_path('app/Modules/' . $this->getModule());

        $this->files->deleteDirectory($modulePath);
    }

    /**
     * Remove module repository from config 
     *
     * @return void
     */
    protected function unsetModuleRepository(): void
    {
        $config = $this->files->get($mongezPath =  base_path('config/mongez.php'));

        $replacementLine = '// Auto generated repositories here: DO NOT remove this line.';
        if (!Str::contains($config, $replacementLine)) return;

        $repositoryName = $this->repositoryName($this->moduleName);
        $repositoryClassName = $this->studly($repositoryName) . 'Repository';

        $module = $this->getModule();

        $replacedString = "'{$repositoryName}' => App\\Modules\\$module\\Repositories\\{$repositoryClassName}::class,";

        $updatedConfig = str_replace($replacedString, "", $config);

        $this->files->put($mongezPath, $updatedConfig);
    }

    /**
     * Delete repository helper function
     *
     * @return void
     */
    protected function removeRepositoryHelper(): void
    {
        try {
            $replacements = [
                '{{ ModuleName }}' => $moduleName = $this->getModule(),
                '{{ repositoryName }}' => $repoName = $this->camel($this->plural($moduleName)),
                '{{ RepositoryName }}' => $this->plural($moduleName),
            ];

            $content = $this->replaceStub('Repositories/helper-function', $replacements);

            $helperFunctionsPath = base_path(config('mongez.console.builder.repository_helpers_path'));
            $helperFunctionsContent = $this->files->get(($helperFunctionsPath));

            $replacementLine = '// Auto generated providers here: DO NOT remove this line.';


            if (!file_exists(($helperFunctionsPath))) {
                $this->warn('file at repository_helpers_path not found, skipping removing helper function.');
                return;
            }

            if (!Str::contains($helperFunctionsContent, $replacementLine)) return;

            $helperFunctionsContent = str_replace("\n" . $content . "\n", "", $helperFunctionsContent);

            $this->files->put($helperFunctionsPath, $helperFunctionsContent);
        } catch (
            \Exception) {
            $this->warn('error removing repository helper, skipping.');
        }
    }

    /**
     * Remove Module seeder from base seeders
     *
     * @return void
     */
    protected function removeFromBaseSeedersClass(): void
    {
        $baseSeedersClass = base_path('database/seeders/DatabaseSeeder.php');

        $baseSeedersContent = $this->files->get($baseSeedersClass);

        $seederClass = $this->studly($this->singular($this->moduleName) . 'Seeder');

        $addedSeederClass = '\\App\\Modules\\' . $this->studly($this->moduleName) . '\\Database\\Seeders\\' . $seederClass . '::class,';

        $this->files->put($baseSeedersClass, str_replace($addedSeederClass, "", $baseSeedersContent));
    }
}
