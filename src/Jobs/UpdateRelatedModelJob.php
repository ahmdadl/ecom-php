<?php

namespace HZ\Illuminate\Mongez\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use HZ\Illuminate\Mongez\Database\Eloquent\ModelEvents;
use HZ\Illuminate\Mongez\Database\Eloquent\MongoDB\Model;

class UpdateRelatedModelJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 60];

    public int $timeout = 120;

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
        $this->afterCommit();
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

        try {
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
        } finally {
            if (in_array(ModelEvents::class, class_uses_recursive($sourceModelClass), true)) {
                $sourceModelClass::resetState();
            }
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Mongez related-model propagation failed', [
            'source' => $this->sourceModelClass,
            'nid' => $this->sourceModelId,
            'event' => $this->event,
            'target' => $this->targetModelClass,
            'error' => $exception->getMessage(),
        ]);
    }
}
