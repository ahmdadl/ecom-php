<?php

namespace HZ\Illuminate\Mongez\Tests\Cache;

use HZ\Illuminate\Mongez\Cache\ModelCacheManager;
use HZ\Illuminate\Mongez\Database\Eloquent\CacheableModel;
use HZ\Illuminate\Mongez\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use MongoDB\BSON\UTCDateTime;

class ModelCacheManagerTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('mongez.cache.enabled', true);
        $app['config']->set('mongez.cache.driver', 'array');
        $app['config']->set('mongez.cache.prefix', 'mongez');
        $app['config']->set('mongez.cache.ttl', 3600);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_it_puts_and_remembers_by_id(): void
    {
        $manager = app(ModelCacheManager::class);

        $model = $this->makeModel(['nid' => 1, 'name' => 'Product']);

        $manager->put($model);

        $resolved = $manager->rememberById(CachedTestModel::class, 1, function () {
            $this->fail('Resolver should not be called when cache is warm');
        });

        $this->assertNotNull($resolved);
        $this->assertEquals(1, $resolved->nid);
        $this->assertEquals('Product', $resolved->name);
    }

    public function test_it_forgets_by_id(): void
    {
        $manager = app(ModelCacheManager::class);

        $model = $this->makeModel(['nid' => 1, 'name' => 'Product']);
        $manager->put($model);

        $manager->forgetById(CachedTestModel::class, 1);

        $resolved = $manager->rememberById(CachedTestModel::class, 1, function () {
            return null;
        });

        $this->assertNull($resolved);
    }

    public function test_it_remembers_by_alternate_column(): void
    {
        $manager = app(ModelCacheManager::class);

        $model = $this->makeModel(['nid' => 2, 'name' => 'By Slug', 'slug' => 'by-slug']);
        $manager->put($model);

        $resolved = $manager->rememberByColumn(CachedTestModel::class, 'slug', 'by-slug', function () {
            $this->fail('Resolver should not be called when index is warm');
        });

        $this->assertNotNull($resolved);
        $this->assertEquals(2, $resolved->nid);
        $this->assertEquals('by-slug', $resolved->slug);
    }

    public function test_it_forgets_alternate_index_when_forgetting_by_id(): void
    {
        $manager = app(ModelCacheManager::class);

        $model = $this->makeModel(['nid' => 2, 'name' => 'By Slug', 'slug' => 'by-slug']);
        $manager->put($model);
        $manager->forgetById(CachedTestModel::class, 2);

        $this->assertFalse(Cache::has('mongez:cached_test_models:col:slug:by-slug'));

        $resolved = $manager->rememberByColumn(CachedTestModel::class, 'slug', 'by-slug', function () {
            return null;
        });

        $this->assertNull($resolved);
    }

    public function test_put_removes_an_old_alternate_index_for_the_same_record(): void
    {
        $manager = app(ModelCacheManager::class);
        $model = $this->makeModel(['nid' => 6, 'name' => 'Product', 'slug' => 'old-slug']);

        $manager->put($model);
        $model->setAttribute('slug', 'new-slug');
        $manager->put($model);

        $this->assertFalse(Cache::has('mongez:cached_test_models:col:slug:old-slug'));
        $this->assertTrue(Cache::has('mongez:cached_test_models:col:slug:new-slug'));
    }

    public function test_invalidating_all_records_bumps_version(): void
    {
        $manager = app(ModelCacheManager::class);

        $model = $this->makeModel(['nid' => 3, 'name' => 'Will Expire']);
        $manager->put($model);

        $manager->invalidateAll(CachedTestModel::class);

        $resolved = $manager->rememberById(CachedTestModel::class, 3, function () {
            return null;
        });

        $this->assertNull($resolved);
    }

    public function test_repeated_invalidate_all_calls_use_distinct_versions(): void
    {
        $manager = app(ModelCacheManager::class);
        $model = $this->makeModel(['nid' => 5, 'name' => 'Will Expire']);

        $manager->put($model);
        $manager->invalidateAll(CachedTestModel::class);
        $firstVersion = Cache::get('mongez:cached_test_models:version');
        $manager->invalidateAll(CachedTestModel::class);
        $secondVersion = Cache::get('mongez:cached_test_models:version');

        $this->assertIsInt($firstVersion);
        $this->assertIsInt($secondVersion);
        $this->assertGreaterThan($firstVersion, $secondVersion);
    }

    public function test_hydrated_model_matches_raw_attributes_and_is_clean(): void
    {
        $manager = app(ModelCacheManager::class);

        $model = $this->makeModel(['nid' => 8, 'name' => 'Hydrated', 'slug' => 'hydrated']);
        // Not in $fillable: the old fill()-based hydration silently dropped
        // such attributes, while a model coming from the DB keeps them.
        $model->setAttribute('internal_flag', 1);
        $manager->put($model);

        $resolved = $manager->rememberById(CachedTestModel::class, 8, function () {
            $this->fail('Resolver should not be called when cache is warm');
        });

        $this->assertNotNull($resolved);
        $this->assertTrue($resolved->exists);
        $this->assertEquals($model->getAttributes(), $resolved->getAttributes());
        $this->assertSame(1, $resolved->getAttribute('internal_flag'));
        $this->assertTrue($resolved->isClean());
    }

    public function test_disabled_caching_skips_cache(): void
    {
        config(['mongez.cache.enabled' => false]);

        $manager = app(ModelCacheManager::class);

        $called = false;
        $resolved = $manager->rememberById(CachedTestModel::class, 4, function () use (&$called) {
            $called = true;

            return $this->makeModel(['nid' => 4, 'name' => 'Disabled']);
        });

        $this->assertTrue($called);
        $this->assertNotNull($resolved);
        $this->assertEquals(4, $resolved->nid);
        $this->assertNull(Cache::get('mongez:cached_test_models:id:4'));
    }

    public function test_put_converts_dates_to_plain_scalars(): void
    {
        $manager = app(ModelCacheManager::class);

        $model = $this->makeModel(['nid' => 9, 'name' => 'Dated']);
        $model->setAttribute('created_at', new UTCDateTime());
        $model->setAttribute('expires_at', now());

        $manager->put($model);

        $payload = (array) Cache::get('mongez:cached_test_models:id:9');

        // Objects must not reach a serializing cache driver: Laravel 13 blocks
        // unserialize of non-allowlisted classes (cache.serializable_classes).
        $this->assertIsString($payload['created_at']);
        $this->assertIsString($payload['expires_at']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $payload['created_at']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $payload['expires_at']);
    }

    public function test_stale_incomplete_class_payload_is_forgotten_and_resolved(): void
    {
        $manager = app(ModelCacheManager::class);

        Cache::put('mongez:cached_test_models:id:7', [
            '__cacheVersion' => 1,
            'nid' => 7,
            'name' => unserialize('O:4:"Nope":0:{}'),
        ], 3600);

        $called = false;
        $resolved = $manager->rememberById(CachedTestModel::class, 7, function () use (&$called) {
            $called = true;

            return $this->makeModel(['nid' => 7, 'name' => 'Fresh']);
        });

        $this->assertTrue($called);
        $this->assertNotNull($resolved);
        $this->assertSame('Fresh', $resolved->getAttribute('name'));
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function makeModel(array $attributes): CachedTestModel
    {
        $model = new CachedTestModel($attributes);
        $model->exists = true;

        return $model;
    }
}

/**
 * @property int $nid
 * @property string $name
 * @property string|null $slug
 */
class CachedTestModel extends Model
{
    use CacheableModel;

    protected $table = 'cached_test_models';
    protected $primaryKey = 'nid';
    public $timestamps = false;

    const USING_CACHE = null;
    const CACHE_ALTERNATE_KEYS = ['slug'];

    protected $fillable = ['nid', 'name', 'slug'];
}
