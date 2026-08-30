<?php

namespace HZ\Illuminate\Mongez\Console\Commands;

use HZ\Illuminate\Mongez\Console\EngezInterface;
use HZ\Illuminate\Mongez\Console\EngezGeneratorCommand;

class EngezRepository extends EngezGeneratorCommand implements EngezInterface
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'engez:repository
                                                {repository} 
                                                {--module=}
                                                {--model=}
                                                {--resource=}
                                                {--filter=}
                                                ' . EngezGeneratorCommand::DATA_TYPES_OPTIONS;

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Make new repository to specific module';

    /**
     * Repository Name
     */
    protected string $repositoryName;

    /**
     * Repository Class Name
     */
    protected string $repositoryClassName;

    /**
     * Search filters list
     *
     * @var array<string, array<int|string, string>>
     */
    protected array $searchFilters = [];

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->init();

        $this->validateArguments();

        $this->create();

        $this->info('Updating configurations...');

        $this->updateConfig();

        $this->info('Repository created successfully');
    }

    /**
     * Set controller info
     * 
     * @return void
     */
    public function init()
    {
        parent::init();

        $this->setModuleName($this->option('module'));

        $this->repositoryName = $this->repositoryName($this->argument('repository'));

        $this->repositoryClassName = $this->studly($this->repositoryName) . 'Repository';
    }

    /**
     * Create the repository file
     * 
     * @return void
     */
    public function create()
    {
        $moduleName = $this->getModule();

        $replacements = [
            // module name
            '{{ ModuleName }}' => $moduleName,
            // repository class
            '{{ RepositoryClass }}' => $this->repositoryClassName,
            // model class EngezRepository
            '{{ ModelName }}' => $this->modelClass($this->optionHasValue('model') ? $this->option('model') : $moduleName),
            // filter class EngezRepository
            '{{ FilterName }}' => $this->filterClass($this->optionHasValue('filter') ? $this->option('filter') : $moduleName),
            // resource class EngezRepository
            '{{ ResourceName }}' => $this->resourceClass($this->optionHasValue('resource') ? $this->option('resource') : $moduleName),
            // Repository Short Name
            '{{ repositoryName }}' => $this->repositoryName,
        ];

        $this->setSearchFilters('inInt', ['nid']);

        $publishedColumn = config('mongez.repository.publishedColumn');

        if (!$this->optionHasValue('bool') && $publishedColumn) {
            $this->input->setOption('bool', $publishedColumn);
        }

        foreach (static::DATA_TYPES as $type) {
            if (!$this->optionHasValue($type)) {
                $data = '[]';
            } else {
                // replace data
                $typeValue = $this->option($type);

                if ($type === 'bool') {
                    if ($publishedColumn && !$this->contains($typeValue, $publishedColumn)) {
                        $typeValue .= ',' . $publishedColumn;
                    }

                    $this->setSearchFilters('bool', explode(',', $typeValue));
                } elseif ($type === 'string') {
                    $searchFiltersLike = [];

                    if ($this->contains($typeValue, 'name')) {
                        $searchFiltersLike[] = 'name';
                    } elseif ($this->contains($typeValue, 'title')) {
                        $searchFiltersLike[] = 'title';
                    }

                    $this->setSearchFilters('like', $searchFiltersLike);
                } elseif ($type === 'locale') {
                    $searchFiltersLike = [];

                    if ($this->contains($typeValue, 'name')) {
                        $searchFiltersLike['name'] = 'name.text';
                    } elseif ($this->contains($typeValue, 'title')) {
                        $searchFiltersLike['title'] = 'name.text';
                    }

                    $this->setSearchFilters('like', $searchFiltersLike);
                }

                $data = $this->stubStringAsArray(explode(',', $typeValue));
            }

            $replacements["{{ $type }}"] = $data;
        }

        $filtersData = '';

        foreach ($this->searchFilters as $filterName => $filterColumns) {
            $filtersData .= $this->replaceStub('Repositories/search-filter', [
                '{{ type }}' => $filterName,
                '{{ data }}' => $this->stubStringAsArray($filterColumns),
            ]);
        }

        $replacements['{{moreSearch}}'] = $filtersData;

        $databaseRepository = $this->isMongoDB() ? 'mongodb-repository' : 'mysql-repository';

        $this->putFile("Repositories/{$this->repositoryClassName}.php", $this->replaceStub('Repositories/' . $databaseRepository, $replacements));
    }

    /**
     * Set search filters
     *
     * @return void
     */
    /**
     * @param array<int|string, string> $searchFilters
     */
    protected function setSearchFilters(string $filterName, array $searchFilters): void
    {
        if ($searchFilters === []) return;

        $existingSearchFilters = $this->searchFilters[$filterName] ?? [];

        $this->searchFilters[$filterName] = array_merge($existingSearchFilters, $searchFilters);
    }
}
