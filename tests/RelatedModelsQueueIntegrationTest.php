<?php

namespace HZ\Illuminate\Mongez\Tests;

use HZ\Illuminate\Mongez\Database\Eloquent\MongoDB\Model;
use HZ\Illuminate\Mongez\Jobs\UpdateRelatedModelJob;
use HZ\Illuminate\Mongez\Tests\Fixtures\Product;
use Illuminate\Support\Facades\Queue;

final class RelatedModelsQueueIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->skipIfMongoDBUnavailable();
        $this->dropCollections('products', 'queue_mode_models');
    }

    protected function skipIfMongoDBUnavailable(): void
    {
        $app = $this->app;
        if (! $app) {
            $this->markTestSkipped('Application is not booted.');
        }

        try {
            $app['db']->connection('mongodb')->getDatabase()->command(['ping' => 1]);
        } catch (\Throwable $e) {
            $this->markTestSkipped('MongoDB is not available: ' . $e->getMessage());
        }
    }

    public function test_queue_mode_per_target_dispatches_update_related_model_job(): void
    {
        Queue::fake();

        QueueModeModel::query()->create(['name' => 'Queued']);

        Queue::assertPushed(UpdateRelatedModelJob::class, function (UpdateRelatedModelJob $job) {
            return $job->sourceModelClass === QueueModeModel::class
                && $job->targetModelClass === QueueTargetModel::class
                && $job->event === 'created'
                && $job->handlerMethod === 'handleCreateSingleModel'
                && $job->afterCommit === true
                && $job->tries === 3;
        });
    }

    public function test_sync_default_does_not_dispatch_related_models_job(): void
    {
        Queue::fake();

        Product::query()->create(['name' => 'Sync', 'price' => 10]);

        Queue::assertNothingPushed();
    }
}

class QueueModeModel extends Model
{
    protected $table = 'queue_mode_models';

    const RELATED_MODELS_QUEUE_MODE = false;

    const RELATED_MODELS_MODES = [
        QueueTargetModel::class => 'queue',
    ];

    const MODEL_LINKS = [
        QueueTargetModel::class => 'queueModeModel',
    ];
}

class QueueTargetModel extends Model
{
    protected $table = 'queue_target_models';
}
