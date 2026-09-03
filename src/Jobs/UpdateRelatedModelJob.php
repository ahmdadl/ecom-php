<?php

namespace HZ\Illuminate\Mongez\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use HZ\Illuminate\Mongez\Database\Eloquent\ModelEvents;
use HZ\Illuminate\Mongez\Database\Eloquent\MongoDB\Model;

class UpdateRelatedModelJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Create a new job instance.
     *
     * @param class-string<Model> $sourceModelClass
     * @param class-string<Model> $targetModelClass
     */
    public function __construct(
        public string $sourceModelClass,
        public int $sourceModelId,
        public string $event,
        public string $handlerMethod,
        public string $targetModelClass,
    ) {
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(): void
    {
        /** @var class-string<Model> $sourceModelClass */
        $sourceModelClass = $this->sourceModelClass;

        $model = $sourceModelClass::find($this->sourceModelId);

        if ($this->event === 'deleted') {
            $model ??= new $sourceModelClass;
            $model->nid = $this->sourceModelId;
        }

        if (! $model) {
            return;
        }

        $method = $this->handlerMethod;
        $sourceModelClass::$method($model, $this->targetModelClass);

        if (in_array(ModelEvents::class, class_uses_recursive($sourceModelClass), true)) {
            $sourceModelClass::resetState();
        }
    }
}
