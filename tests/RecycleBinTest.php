<?php

namespace HZ\Illuminate\Mongez\Tests;

use HZ\Illuminate\Mongez\Tests\Fixtures\Product;
use Illuminate\Support\Facades\DB;

final class RecycleBinTest extends TestCase
{
    /**
     * {@inheritDoc}
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->dropCollections('products', 'productsTrash');
    }

    public function test_delete_moves_record_to_trash_with_primary_nid(): void
    {
        $product = Product::query()->create(['name' => 'Trash Me']);
        $nid = $product->nid;

        $product->delete();

        $this->assertSame(0, DB::table('products')->where('nid', $nid)->count());

        $trashRecords = DB::table('productsTrash')->where('primaryId', $nid)->get();

        $this->assertCount(1, $trashRecords);
        $firstTrashRecord = $trashRecords->first();
        $this->assertNotNull($firstTrashRecord);
        $this->assertSame('Trash Me', $firstTrashRecord->record['name']);
    }

    public function test_find_deleted_locates_trashed_record_by_nid(): void
    {
        $product = Product::query()->create(['name' => 'Find Me']);
        $nid = $product->nid;
        $this->assertNotNull($nid);

        $product->delete();

        $deletedRecord = Product::findDeleted($nid);

        $this->assertNotNull($deletedRecord);
        $this->assertSame($nid, $deletedRecord->nid);
        $this->assertSame('Find Me', $deletedRecord->name);
    }

    public function test_restore_all_restores_records_with_their_original_nid(): void
    {
        $product = Product::query()->create(['name' => 'Restore Me']);
        $nid = $product->nid;

        $product->delete();

        Product::restoreAll();

        $restored = Product::find($nid);

        $this->assertNotNull($restored);
        $this->assertSame($nid, $restored->getKey());
        $this->assertSame('Restore Me', $restored->name);

        $this->assertSame(0, DB::table('productsTrash')->where('primaryId', $nid)->count());
    }
}
