<?php

namespace HZ\Illuminate\Mongez\Tests;

use HZ\Illuminate\Mongez\Tests\Fixtures\Product;
use Illuminate\Support\Facades\DB;

final class ModelNidTest extends TestCase
{
    /**
     * {@inheritDoc}
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->dropCollections('products', 'ids');
    }

    public function test_create_assigns_auto_incremented_integer_nid(): void
    {
        $product = Product::query()->create(['name' => 'First']);

        $this->assertIsInt($product->nid);
        $this->assertGreaterThan(0, $product->nid);

        $secondProduct = Product::query()->create(['name' => 'Second']);

        $this->assertGreaterThan($product->nid, $secondProduct->nid);

        $counter = DB::table('ids')->where('collection', 'products')->first();

        $this->assertNotNull($counter);
        $this->assertSame($secondProduct->nid, (int) $counter->id);
    }

    public function test_id_attribute_is_the_document_object_id_not_the_nid(): void
    {
        $product = Product::query()->create(['name' => 'First']);

        $product->refresh();

        $documentId = (string) $product->id;

        $this->assertMatchesRegularExpression('/^[0-9a-f]{24}$/i', $documentId);
        $this->assertNotSame((string) $product->nid, $documentId);
    }

    public function test_find_queries_by_nid(): void
    {
        $product = Product::query()->create(['name' => 'First']);

        $found = Product::find($product->nid);

        $this->assertSame($product->nid, $found->getKey()); // @phpstan-ignore method.nonObject

        $this->assertNull(Product::find($product->nid + 1000000));
    }

    public function test_shared_info_hides_document_id_and_keeps_nid(): void
    {
        $product = Product::query()->create(['name' => 'First']);

        $sharedInfo = $product->sharedInfo();

        $this->assertSame($product->nid, $sharedInfo['nid']);
        $this->assertSame('First', $sharedInfo['name']);
        $this->assertArrayNotHasKey('id', $sharedInfo);
        $this->assertArrayNotHasKey('_id', $sharedInfo);
    }

    public function test_get_nid_returns_next_counter_value_for_unsaved_models(): void
    {
        $firstPeekedNid = new Product()->getNid();
        $secondPeekedNid = new Product()->getNid();

        /** @phpstan-ignore-next-line method.alreadyNarrowedType */
        $this->assertIsInt($firstPeekedNid);
        $this->assertSame($firstPeekedNid, $secondPeekedNid);
    }

    public function test_each_model_class_resolves_its_own_collection_name(): void
    {
        $this->assertSame('products', Product::tableName());
        $this->assertSame('categories', Category::tableName());
    }
}

final class Category extends \HZ\Illuminate\Mongez\Database\Eloquent\MongoDB\Model
{
    protected $table = 'categories';
}
