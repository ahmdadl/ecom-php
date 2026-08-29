<?php

namespace HZ\Illuminate\Mongez\Tests;

use HZ\Illuminate\Mongez\Tests\Fixtures\Product;
use HZ\Illuminate\Mongez\Tests\Fixtures\ProductResource;
use ReflectionMethod;

final class JsonResourceTest extends TestCase
{
    /**
     * {@inheritDoc}
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->dropCollections('products');
    }

    public function test_data_collection_exposes_nid_without_document_id(): void
    {
        $product = Product::query()->create(['name' => 'Cup', 'price' => 7]);

        $data = new ProductResource($product)->toArray(null);

        $this->assertSame($product->nid, $data['nid']);
        $this->assertSame('Cup', $data['name']);
        $this->assertSame(7, $data['price']);
        $this->assertArrayNotHasKey('id', $data);
        $this->assertArrayNotHasKey('_id', $data);
    }

    public function test_id_and_nid_helpers_return_nid(): void
    {
        $product = Product::query()->create(['name' => 'Cup']);

        $resource = new ProductResource($product);
        $resource->toArray(null);

        $idMethod = new ReflectionMethod($resource, 'id');

        $nidMethod = new ReflectionMethod($resource, 'nid');

        $this->assertSame($product->nid, $idMethod->invoke($resource));
        $this->assertSame($product->nid, $nidMethod->invoke($resource));
    }
}
