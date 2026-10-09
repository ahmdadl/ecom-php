<?php

declare(strict_types=1);

namespace HZ\Illuminate\Mongez\Tests;

use HZ\Illuminate\Mongez\Resources\JsonResourceManager;
use HZ\Illuminate\Mongez\Tests\Fixtures\Product;
use HZ\Illuminate\Mongez\Tests\Fixtures\ProductResource;
use HZ\Illuminate\Mongez\Tests\Fixtures\ProductsRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

final class ListDetailTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->dropCollections('products');
    }

    private function repository(): ProductsRepository
    {
        return new ProductsRepository(new Request(), new \HZ\Illuminate\Mongez\Events\Events());
    }

    public function test_list_marks_resources_as_listing(): void
    {
        $repository = $this->repository();

        $repository->create(['name' => 'Chair', 'price' => 10]);

        $records = $repository->list([]);

        $this->assertCount(1, $records);

        $resource = $records->first();

        $this->assertNotNull($resource);
        $this->assertInstanceOf(ProductResource::class, $resource);
        $this->assertTrue($resource->isListing());
    }

    public function test_detail_paths_leave_listing_flag_false(): void
    {
        $repository = $this->repository();

        $product = $repository->create(['name' => 'Desk', 'price' => 20]);

        $fromGet = $repository->get($product->nid);
        $this->assertInstanceOf(ProductResource::class, $fromGet);
        $this->assertFalse($fromGet->isListing());

        $model = $repository->getModel($product->nid);
        $this->assertNotNull($model);
        $wrapped = $repository->wrap($model);
        $this->assertInstanceOf(ProductResource::class, $wrapped);
        $this->assertFalse($wrapped->isListing());

        $this->assertFalse((new ProductResource($product))->isListing());
    }

    public function test_prime_listing_called_once_per_list_and_never_on_detail(): void
    {
        PrimeCountingProductsRepository::$primeCalls = 0;
        PrimeCountingProductsRepository::$primedCount = 0;

        $repository = new PrimeCountingProductsRepository(new Request(), new \HZ\Illuminate\Mongez\Events\Events());

        $repository->create(['name' => 'A', 'price' => 1]);
        $repository->create(['name' => 'B', 'price' => 2]);

        $this->assertSame(0, PrimeCountingProductsRepository::$primeCalls);

        $records = $repository->list([]);

        $this->assertSame(1, PrimeCountingProductsRepository::$primeCalls);
        $this->assertCount(2, $records);
        $this->assertSame(2, PrimeCountingProductsRepository::$primedCount);

        // `as-model` still primes (raw models returned, no resources to flag).
        PrimeCountingProductsRepository::$primeCalls = 0;
        $models = $repository->list(['as-model' => true]);
        $this->assertSame(1, PrimeCountingProductsRepository::$primeCalls);
        $this->assertCount(2, $models);

        // Detail paths never prime.
        PrimeCountingProductsRepository::$primeCalls = 0;
        $firstModel = $models->first();
        $this->assertNotNull($firstModel);
        $repository->get($firstModel->nid);
        $this->assertSame(0, PrimeCountingProductsRepository::$primeCalls);
    }

    public function test_nested_resources_inherit_listing_flag(): void
    {
        $parent = new ListingFlagParentResource(new \Illuminate\Support\Fluent([
            'child' => ['name' => 'nested'],
        ]));

        // Detail rendering: parent and child both see false.
        $data = $parent->toArray(null);
        $this->assertFalse($data['sawListing']);
        $this->assertFalse($this->resolveResource($data['child'])['sawListing']);

        // Listing rendering: flag propagates to the nested resource.
        $parent = (new ListingFlagParentResource(new \Illuminate\Support\Fluent([
            'child' => ['name' => 'nested'],
        ])))->asListing();

        $data = $parent->toArray(null);
        $this->assertTrue($data['sawListing']);
        $this->assertTrue($this->resolveResource($data['child'])['sawListing']);
    }

    public function test_collectable_items_inherit_listing_flag(): void
    {
        $parent = (new ListingFlagCollectParentResource(new \Illuminate\Support\Fluent([
            'children' => [['name' => 'one'], ['name' => 'two']],
        ])))->asListing();

        $data = $parent->toArray(null);

        $resolved = $this->resolveResource($data['children']);

        $this->assertCount(2, $resolved);
        foreach ($resolved as $child) {
            $this->assertTrue($child['sawListing']);
        }
    }

    /**
     * Resolve a nested resource / resource collection into plain arrays,
     * the way the HTTP layer does when serializing the response.
     */
    private function resolveResource(mixed $value): mixed
    {
        if ($value instanceof JsonResourceManager) {
            return $value->toArray(null);
        }

        if ($value instanceof \Illuminate\Http\Resources\Json\ResourceCollection) {
            $resolved = [];

            foreach ($value->collection ?? [] as $item) {
                $resolved[] = $item instanceof JsonResourceManager ? $item->toArray(null) : (array) $item;
            }

            return $resolved;
        }

        if ($value instanceof \Illuminate\Support\Collection) {
            return $value->map(fn ($item) => $this->resolveResource($item))->all();
        }

        if (is_array($value)) {
            return array_map(fn ($item) => $this->resolveResource($item), $value);
        }

        return $value;
    }
}

class PrimeCountingProductsRepository extends ProductsRepository
{
    public static int $primeCalls = 0;

    public static int $primedCount = 0;

    protected function primeListing(Collection $records): void
    {
        static::$primeCalls++;
        static::$primedCount = $records->count();
    }
}

class ListingFlagChildResource extends JsonResourceManager
{
    const DATA = ['name'];

    public const WHEN_AVAILABLE = true;

    protected function extend(?\Illuminate\Http\Request $request = null): void
    {
        $this->set('sawListing', $this->isListing());
    }
}

class ListingFlagParentResource extends JsonResourceManager
{
    const RESOURCES = [
        'child' => ListingFlagChildResource::class,
    ];

    public const WHEN_AVAILABLE = true;

    protected function extend(?\Illuminate\Http\Request $request = null): void
    {
        $this->set('sawListing', $this->isListing());
    }
}

class ListingFlagCollectParentResource extends JsonResourceManager
{
    const COLLECTABLE = [
        'children' => ListingFlagChildResource::class,
    ];

    public const WHEN_AVAILABLE = true;
}
