<?php

namespace HZ\Illuminate\Mongez\Console\Commands;

use HZ\Illuminate\Mongez\Console\EngezInterface;
use HZ\Illuminate\Mongez\Console\EngezGeneratorCommand;

class EngezTrait extends EngezGeneratorCommand implements EngezInterface
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'engez:trait {trait}
                                        {--module=}
                                        {--type=general}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create new trait to the given module';

    /**
     * The resource name.
     */
    protected string $traitName;

    /**
     * The trait general type.
     *
     * @var string
     */
    const TYPE_GENERAL = 'general';

    /**
     * The trait general type.
     *
     * @var string
     */
    const TYPE_REQUEST = 'request';

    /**
     * The available trait types.
     *
     * @var array<int, string>
     */
    const TRAIT_TYPES = [self::TYPE_GENERAL, self::TYPE_REQUEST];


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

        $this->info('Trait has been created successfully');
    }

    /**
     * Prepare data
     *
     * @return void
     */
    public function init()
    {
        parent::init();

        $this->setModuleName($this->stringOption('module'));

        $this->traitName = $this->stringArgument('trait');

    }

    /**
     * Validate The trait type
     *
     * @return void
     */
    public function validateArguments()
    {
        parent::validateArguments();

        if (!in_array($this->option('type'), [static::TYPE_GENERAL, static::TYPE_REQUEST])) {
            $this->missingRequiredOption('This trait type does not exits, Did you mean? ' . implode(PHP_EOL, static::TRAIT_TYPES));

            return;
        }

        $baseDir = $this->isRequestTrait() ? 'Traits/Validation' : 'Traits';

        if ($this->files->exists($this->modulePath("$baseDir/$this->traitName.php"))) {
            $this->terminate('You already have this trait');
        }
    }

    /**
     * Create Model
     *
     * @return void
     */
    public function create()
    {
        $traitName = $this->stringArgument('trait');

        $replacements = [
            // module name
            '{{ ModuleName }}' => $this->getModule(),
            // trait name
            '{{ TraitName }}' => $traitName,
        ];

        if ($this->isRequestTrait()) {
            $path = "Traits/Validation/{$traitName}.php";
            $stubPath = 'Traits/Validation/with-common-rules-trait';
        } else {
            $path = "Traits/{$traitName}.php";
            $stubPath = 'Traits/trait';
        }

        $this->putFile($path, $this->replaceStub($stubPath, $replacements));
    }

    /**
     * Determine if current generated trait is request validation trait.
     */
    protected function isRequestTrait(): bool
    {
        return $this->option('type') ===  static::TYPE_REQUEST;
    }

    /**
     * Determine if current generated trait is general trait.
     */
    protected function isGeneralTrait(): bool
    {
        return $this->option('type') ===  static::TYPE_GENERAL;
    }
}
