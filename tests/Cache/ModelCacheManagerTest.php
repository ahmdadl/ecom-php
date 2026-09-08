<?php

namespace HZ\Illuminate\Mongez\Tests\Cache;

use HZ\Illuminate\Mongez\Cache\ModelCacheManager;
use HZ\Illuminate\Mongez\Database\Eloquent\CacheableModel;
use HZ\Illuminate\Mongez\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

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
