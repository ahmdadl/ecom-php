<?php

namespace HZ\Illuminate\Mongez\Tests;

use HZ\Illuminate\Mongez\Database\Eloquent\ModelEvents;
use HZ\Illuminate\Mongez\Database\Eloquent\MongoDB\Model;
use HZ\Illuminate\Mongez\Jobs\UpdateRelatedModelJob;
use PHPUnit\Framework\TestCase;

final class RelatedModelsJobTest extends TestCase
{
    protected function setUp(): void
    {
        JobHandlerModelStub::$found = null;
        JobHandlerModelStub::$calls = [];
        JobHandlerModelStub::resetState();
    }

    public function test_job_calls_target_handler_with_target_class_filter(): void
    {
        $model = new JobHandlerModelStub();
        $model->nid = 123;
        JobHandlerModelStub::$found = $model;

        $job = new UpdateRelatedModelJob(JobHandlerModelStub::class, 123, 'created', 'handleCreateSingleModel', TargetModelStub::class);
        $job->handle();

        $this->assertSame([['handleCreateSingleModel', 123, TargetModelStub::class]], JobHandlerModelStub::$calls);
    }

    public function test_job_calls_array_handler_for_create_event(): void
    {
        $model = new JobHandlerModelStub();
        $model->nid = 456;
        JobHandlerModelStub::$found = $model;

        $job = new UpdateRelatedModelJob(JobHandlerModelStub::class, 456, 'created', 'handleCreateArrayModel', TargetModelStub::class);
        $job->handle();

        $this->assertSame([['handleCreateArrayModel', 456, TargetModelStub::class]], JobHandlerModelStub::$calls);
    }

    public function test_job_calls_unset_handler_for_deleted_event(): void
    {
        $model = new JobHandlerModelStub();
        $model->nid = 789;
        JobHandlerModelStub::$found = $model;

        $job = new UpdateRelatedModelJob(JobHandlerModelStub::class, 789, 'deleted', 'handleUnsetSingleModel', TargetModelStub::class);
        $job->handle();

        $this->assertSame([['handleUnsetSingleModel', 789, TargetModelStub::class]], JobHandlerModelStub::$calls);
    }

    public function test_job_creates_temporary_model_for_deleted_event_when_source_is_gone(): void
    {
        JobHandlerModelStub::$found = null;

        $job = new UpdateRelatedModelJob(JobHandlerModelStub::class, 999, 'deleted', 'handleDeleteSingleModel', TargetModelStub::class);
        $job->handle();

        $this->assertSame([['handleDeleteSingleModel', 999, TargetModelStub::class]], JobHandlerModelStub::$calls);
    }

    public function test_job_skips_when_source_model_is_missing_for_create_or_update(): void
    {
        JobHandlerModelStub::$found = null;

        $job = new UpdateRelatedModelJob(JobHandlerModelStub::class, 111, 'created', 'handleCreateSingleModel', TargetModelStub::class);
        $job->handle();

        $job = new UpdateRelatedModelJob(JobHandlerModelStub::class, 111, 'updated', 'handleUpdateSingleModel', TargetModelStub::class);
        $job->handle();

        $this->assertSame([], JobHandlerModelStub::$calls);
    }

    public function test_reassociate_updates_matching_array_document_by_nid(): void
    {
        $model = new AssociatableModelStub();
        $model->setAttribute('items', [
            ['nid' => 1, 'name' => 'First'],
            ['nid' => 2, 'name' => 'Second'],
        ]);

        $model->reassociate(['nid' => 2, 'name' => 'Updated Second'], 'items');

        $items = $model->getAttribute('items');
        $this->assertSame(['nid' => 2, 'name' => 'Updated Second'], (array) $items[1]);
        $this->assertCount(2, $items);
    }

    public function test_reassociate_updates_matching_stdclass_document_by_nid(): void
    {
        $model = new AssociatableModelStub();
        $model->setAttribute('items', [
            (object) ['nid' => 1, 'name' => 'First'],
            (object) ['nid' => 2, 'name' => 'Second'],
        ]);

        $model->reassociate(['nid' => 2, 'name' => 'Updated Second'], 'items');

        $items = $model->getAttribute('items');
        $this->assertSame(['nid' => 2, 'name' => 'Updated Second'], (array) $items[1]);
    }

    public function test_reassociate_appends_non_matching_document(): void
    {
        $model = new AssociatableModelStub();
        $model->setAttribute('items', [
            ['nid' => 1, 'name' => 'First'],
        ]);

        $model->reassociate(['nid' => 3, 'name' => 'Third'], 'items');

        $items = $model->getAttribute('items');
        $this->assertCount(2, $items);
        $this->assertSame(['nid' => 3, 'name' => 'Third'], (array) $items[1]);
    }

    public function test_disassociate_removes_matching_array_document_by_nid(): void
    {
        $model = new AssociatableModelStub();
        $model->setAttribute('items', [
            ['nid' => 1, 'name' => 'First'],
            ['nid' => 2, 'name' => 'Second'],
        ]);

        $model->disassociate(['nid' => 1], 'items');

        $items = $model->getAttribute('items');
        $this->assertCount(1, $items);
        $this->assertSame(['nid' => 2, 'name' => 'Second'], (array) $items[0]);
    }

    public function test_disassociate_removes_matching_stdclass_document_by_nid(): void
    {
        $model = new AssociatableModelStub();
        $model->setAttribute('items', [
            (object) ['nid' => 1, 'name' => 'First'],
            (object) ['nid' => 2, 'name' => 'Second'],
        ]);

        $model->disassociate(['nid' => 1], 'items');

        $items = $model->getAttribute('items');
        $this->assertCount(1, $items);
        $this->assertSame(['nid' => 2, 'name' => 'Second'], (array) $items[0]);
    }

    public function test_disassociate_keeps_non_matching_documents(): void
    {
        $model = new AssociatableModelStub();
        $model->setAttribute('items', [
            ['nid' => 1, 'name' => 'First'],
            ['nid' => 2, 'name' => 'Second'],
        ]);

        $model->disassociate(['nid' => 99], 'items');

        $items = $model->getAttribute('items');
        $this->assertCount(2, $items);
    }

    public function test_set_model_options_does_not_double_nid_suffix(): void
    {
        JobHandlerModelStub::setModelOptions(['category.nid', 'category', 'sharedInfo']);

        $options = JobHandlerModelStub::$modelOptions;
        $this->assertSame('category.nid', $options[0]['searchingColumn']);
    }

    public function test_should_run_related_models_on_queue_defaults_to_sync(): void
    {
        $this->assertFalse(QueueModeHelperStub::shouldRunRelatedModelsOnQueue());
    }

    public function test_should_run_related_models_on_queue_returns_true_for_boolean(): void
    {
        $this->assertTrue(QueueModeHelperStubEnabled::shouldRunRelatedModelsOnQueue());
    }

    public function test_should_run_related_models_on_queue_returns_true_for_string(): void
    {
        $this->assertTrue(QueueModeHelperStubString::shouldRunRelatedModelsOnQueue());
    }

    public function test_should_run_related_model_on_queue_uses_per_target_override(): void
    {
        $this->assertTrue(QueueModeHelperStubPerTarget::shouldRunRelatedModelOnQueue(TargetModelClass::class));
        $this->assertFalse(QueueModeHelperStubPerTarget::shouldRunRelatedModelOnQueue(OtherTargetModelClass::class));
    }

    public function test_should_run_related_model_on_queue_falls_back_to_default(): void
    {
        $this->assertTrue(QueueModeHelperStubEnabled::shouldRunRelatedModelOnQueue(TargetModelStub::class));
        $this->assertFalse(QueueModeHelperStub::shouldRunRelatedModelOnQueue(TargetModelStub::class));
    }
}

final class JobHandlerModelStub extends Model
{
    public static ?self $found = null;

    /** @var array<int, array<int, string|int|null>> */
    public static array $calls = [];

    public static function find($id): ?static
    {
        return static::$found;
    }

    public static function handleCreateSingleModel(Model $model, ?string $targetModelClass = null): void
    {
        static::$calls[] = ['handleCreateSingleModel', (int) $model->nid, $targetModelClass];
    }

    public static function handleCreateArrayModel(Model $model, ?string $targetModelClass = null): void
    {
        static::$calls[] = ['handleCreateArrayModel', (int) $model->nid, $targetModelClass];
    }

    public static function handleUpdateSingleModel(Model $model, ?string $targetModelClass = null): void
    {
        static::$calls[] = ['handleUpdateSingleModel', (int) $model->nid, $targetModelClass];
    }

    public static function handleUpdateArrayModel(Model $model, ?string $targetModelClass = null): void
    {
        static::$calls[] = ['handleUpdateArrayModel', (int) $model->nid, $targetModelClass];
    }

    public static function handleUnsetSingleModel(Model $model, ?string $targetModelClass = null): void
    {
        static::$calls[] = ['handleUnsetSingleModel', (int) $model->nid, $targetModelClass];
    }

    public static function handlePullArrayModel(Model $model, ?string $targetModelClass = null): void
    {
        static::$calls[] = ['handlePullArrayModel', (int) $model->nid, $targetModelClass];
    }

    public static function handleDeleteSingleModel(Model $model, ?string $targetModelClass = null): void
    {
        static::$calls[] = ['handleDeleteSingleModel', (int) $model->nid, $targetModelClass];
    }
}

class AssociatableModelStub extends Model
{
    protected $table = 'associatable_test';
}

class TargetModelStub extends Model
{
    protected $table = 'target_test';
}

class OtherTargetModelStub extends Model
{
    protected $table = 'other_target_test';
}

class QueueModeHelperStub
{
    use ModelEvents;

    public const ON_MODEL_CREATE = [];
    public const ON_MODEL_CREATE_PUSH = [];
    public const ON_MODEL_UPDATE = [];
    public const ON_MODEL_UPDATE_ARRAY = [];
    public const ON_MODEL_DELETE_UNSET = [];
    public const ON_MODEL_DELETE_PULL = [];
    public const ON_MODEL_DELETE = [];
    public const MODEL_LINKS = [];
    public const MODEL_LINKS_ARRAY = [];
    public const MODEL_LINKS_DELETE = [];
}

class QueueModeHelperStubEnabled extends QueueModeHelperStub
{
    public const RELATED_MODELS_QUEUE_MODE = true;
}

class QueueModeHelperStubString extends QueueModeHelperStub
{
    public const RELATED_MODELS_QUEUE_MODE = 'queue';
}

class QueueModeHelperStubPerTarget extends QueueModeHelperStub
{
    public const RELATED_MODELS_MODES = [
        TargetModelClass::class => 'queue',
        OtherTargetModelClass::class => 'sync',
    ];
}

class TargetModelClass
{
}

class OtherTargetModelClass
{
}
