<?php

namespace HZ\Illuminate\Mongez\Tests\Cache;

use HZ\Illuminate\Mongez\Cache\ModelCacheManager;
use HZ\Illuminate\Mongez\Database\Eloquent\CacheableModel;
use HZ\Illuminate\Mongez\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class CacheBugTest extends TestCase
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

    /**
     * BUG: put() uses toArray() which fires accessors, so the cache stores
     * transformed data instead of raw attributes. When hydrated via
     * setRawAttributes(), the raw attribute holds the accessor output, not
     * the original DB value.
     *
     * If the accessor is non-idempotent or context-dependent, the hydrated
     * model will produce incorrect values.
     */
    public function test_cache_stores_raw_attributes_not_accessor_output(): void
    {
        $manager = app(ModelCacheManager::class);

        // Create a model with an accessor that transforms data
        $model = new CachedTestModelWithAccessor(['nid' => 10, 'name' => 'john']);
        $model->exists = true;

        // The accessor transforms "john" → "John" when accessed
        $this->assertEquals('John', $model->name);

        // But the raw attribute should still be "john"
        $this->assertEquals('john', $model->getAttributes()['name']);

        // Cache the model
        $manager->put($model);

        // Hydrate from cache
        $hydrated = $manager->rememberById(CachedTestModelWithAccessor::class, 10, function () {
            $this->fail('Resolver should not be called when cache is warm');
        });

        $this->assertNotNull($hydrated);

        // The raw attribute in the hydrated model should match the original
        // raw attribute ("john"), NOT the accessor output ("John").
        //
        // This assertion FAILS because put() uses toArray() which fires
        // the accessor and stores "John" as the raw attribute.
        $this->assertSame(
            $model->getAttributes(),
            $hydrated->getAttributes(),
            'Hydrated model attributes should match original raw attributes, not accessor output'
        );
    }
}

/**
 * Test model with a non-idempotent accessor.
 */
class CachedTestModelWithAccessor extends Model
{
    use CacheableModel;

    protected $table = 'cached_test_models_accessor';
    protected $primaryKey = 'nid';
    public $timestamps = false;

    const USING_CACHE = true;

    protected $fillable = ['nid', 'name'];

    /**
     * Accessor that capitalizes the first letter.
     */
    public function getNameAttribute(mixed $value): string
    {
        return ucfirst((string) $value);
    }
}
