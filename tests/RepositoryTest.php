<?php

namespace HZ\Illuminate\Mongez\Tests;

use HZ\Illuminate\Mongez\Events\Events;
use HZ\Illuminate\Mongez\Tests\Fixtures\Product;
use HZ\Illuminate\Mongez\Tests\Fixtures\ProductResource;
use HZ\Illuminate\Mongez\Tests\Fixtures\ProductsRepository;
use Illuminate\Http\Request;

final class RepositoryTest extends TestCase
{
    /**
     * {@inheritDoc}
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->dropCollections('products');
    }

    /**
     * Create a fresh products repository
     *
     * @return ProductsRepository
     */
    private function repository(): ProductsRepository
    {
        return new ProductsRepository(new Request(), new Events());
    }

    public function test_create_persists_model_with_nid_and_casts_integer_data(): void
    {
        $repository = $this->repository();

        $product = $repository->create(['name' => 'Chair', 'price' => '25']);

        $storedProduct = Product::query()->where('nid', $product->nid)->first();

        $this->assertNotNull($storedProduct);
        $this->assertSame('Chair', $storedProduct->name);
        $this->assertSame(25, $storedProduct->price);
        $this->assertIsInt($storedProduct->nid);
    }

    public function test_list_wraps_records_into_resources(): void
    {
        $repository = $this->repository();

        $repository->create(['name' => 'Table', 'price' => 99]);

        $records = $repository->list([]);

        $this->assertCount(1, $records);
        $this->assertInstanceOf(ProductResource::class, $records->first());
    }

    public function test_has_checks_by_nid_by_default(): void
    {
        $repository = $this->repository();

        $product = $repository->create(['name' => 'Sofa']);

        $this->assertTrue($repository->has($product->nid));
        $this->assertFalse($repository->has($product->nid + 500000));
    }

    public function test_get_returns_resource_for_nid(): void
    {
        $repository = $this->repository();

        $product = $repository->create(['name' => 'Desk']);

        $resource = $repository->get($product->nid);

        $this->assertNotNull($resource);
        $this->assertSame($product->nid, $resource->toArray(null)['nid']);

        $this->assertNull($repository->get($product->nid + 500000));
    }

    public function test_publish_toggles_published_flag_by_nid(): void
    {
        $repository = $this->repository();

        $product = $repository->create(['name' => 'Lamp']);

        $this->assertNull($repository->getPublishedModel($product->nid));

        $repository->publish($product->nid, true);
        $this->assertNotNull($repository->getPublishedModel($product->nid));

        $repository->publish($product->nid, false);
        $this->assertNull($repository->getPublishedModel($product->nid));
    }
}
