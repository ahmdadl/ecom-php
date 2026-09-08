<?php

namespace HZ\Illuminate\Mongez\Tests;

use HZ\Illuminate\Mongez\Tests\Fixtures\LegacyIdProductResource;
use HZ\Illuminate\Mongez\Tests\Fixtures\Product;
use HZ\Illuminate\Mongez\Tests\Fixtures\SharedInfoProduct;

final class IdAliasesNidTest extends TestCase
{
    /**
     * {@inheritDoc}
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->dropCollections('products', 'shared_info_products', 'ids');
        config(['mongez.mongodb.id_aliases_nid' => false]);
    }

    public function test_shim_off_keeps_object_id_on_id_attribute(): void
    {
        $product = Product::query()->create(['name' => 'First']);
        $product->refresh();

        $documentId = (string) $product->id;

        $this->assertMatchesRegularExpression('/^[0-9a-f]{24}$/i', $documentId);
        $this->assertNotSame((string) $product->nid, $documentId);
    }

    public function test_shim_on_returns_integer_nid_from_id_attribute(): void
    {
        config(['mongez.mongodb.id_aliases_nid' => true]);

        $product = Product::query()->create(['name' => 'First']);
        $product->refresh();

        $this->assertSame($product->nid, $product->id);
        $this->assertIsInt($product->id);
    }

    public function test_shim_on_shared_info_emits_integer_id_when_listed(): void
    {
        config(['mongez.mongodb.id_aliases_nid' => true]);

        $product = SharedInfoProduct::query()->create(['name' => 'Embed']);

        $sharedInfo = $product->sharedInfo();

        $this->assertSame($product->nid, $sharedInfo['id']);
        $this->assertSame('Embed', $sharedInfo['name']);
        $this->assertArrayNotHasKey('_id', $sharedInfo);
    }

    public function test_shim_off_shared_info_still_drops_id_even_when_listed(): void
    {
        $product = SharedInfoProduct::query()->create(['name' => 'Embed']);

        $sharedInfo = $product->sharedInfo();

        $this->assertArrayNotHasKey('id', $sharedInfo);
        $this->assertSame('Embed', $sharedInfo['name']);
    }

    public function test_shim_on_resource_integer_id_resolves_nid(): void
    {
        config(['mongez.mongodb.id_aliases_nid' => true]);

        $product = Product::query()->create(['name' => 'Cup', 'price' => 7]);

        $data = new LegacyIdProductResource($product)->toArray(null);

        $this->assertSame($product->nid, $data['id']);
        $this->assertSame(7, $data['price']);
        $this->assertSame('Cup', $data['name']);
    }

    public function test_shim_off_resource_integer_id_corrupts_object_id_cast(): void
    {
        $product = Product::query()->create(['name' => 'Cup', 'price' => 7]);
        $product->refresh();

        $data = new LegacyIdProductResource($product)->toArray(null);

        // Without the shim, (int) ObjectId-hex string is leading digits only — not nid.
        $this->assertNotSame($product->nid, $data['id']);
        $this->assertIsInt($data['id']);
    }
}
