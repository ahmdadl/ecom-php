<?php
namespace HZ\Illuminate\Mongez\Tests;

use Illuminate\Support\Facades\Artisan;
use HZ\Illuminate\Mongez\Database\Eloquent\MongoDB\Database;

class EdgeCaseTest extends TestCase
{
    protected function setUp(): void { parent::setUp(); $this->dropCollections('widgets', 'widgetsTrash', 'gadgets', 'ids'); }

    public function test_verify_only_gates_on_leftovers(): void
    {
        $db = Database::getDatabase();
        $db->selectCollection('widgets')->insertOne(['_id' => 1, 'id' => 42]);
        $code = Artisan::call('mongez:migrate-nid', ['--collection' => ['widgets'], '--phase' => ['verify']]);
        $this->assertSame(1, $code, 'verify-only must gate on leftover id keys');
        $this->assertStringContainsString('still carry an `id` key', Artisan::output());
    }

    public function test_nonexistent_collection_is_a_noop(): void
    {
        $code = Artisan::call('mongez:migrate-nid', ['--collection' => ['nonexistent'], '--execute' => true]);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('collections: 1', Artisan::output());
    }

    public function test_include_trash_renames_trash_collections(): void
    {
        $db = Database::getDatabase();
        $db->selectCollection('widgets')->insertOne(['_id' => 1, 'id' => 10]);
        $db->selectCollection('widgetsTrash')->insertOne(['_id' => 1, 'id' => 20]);
        $code = Artisan::call('mongez:migrate-nid', ['--collection' => ['widgets', 'widgetsTrash'], '--include-trash' => true, '--execute' => true]);
        $this->assertSame(0, $code);
        $this->assertSame(0, $db->selectCollection('widgetsTrash')->countDocuments(['id' => ['$exists' => true]]));
        $this->assertSame(1, $db->selectCollection('widgetsTrash')->countDocuments(['nid' => ['$exists' => true]]));
    }

    public function test_counter_advances_past_trash_id_when_trash_not_renamed(): void
    {
        $db = Database::getDatabase();
        $db->selectCollection('widgets')->insertOne(['_id' => 1, 'id' => 10]);
        $db->selectCollection('widgetsTrash')->insertOne(['_id' => 1, 'id' => 999]);
        $code = Artisan::call('mongez:migrate-nid', ['--collection' => ['widgets'], '--execute' => true]);
        $this->assertSame(0, $code);
        $row = $db->selectCollection('ids')->findOne(['collection' => 'widgets'], ['typeMap' => ['root' => 'array']]);
        $this->assertIsArray($row);
        $this->assertSame(999, $row['id'], 'counter must advance past the Trash id even when Trash is not renamed');
    }

    public function test_empty_collection_gets_default_nid_index(): void
    {
        $db = Database::getDatabase();
        $db->createCollection('widgets');
        $code = Artisan::call('mongez:migrate-nid', ['--collection' => ['widgets'], '--execute' => true]);
        $this->assertSame(0, $code);
        $indexes = [];
        foreach ($db->selectCollection('widgets')->listIndexes() as $idx) {
            $indexes[] = $idx->getName();
        }
        $this->assertContains('nid_1', $indexes);
    }
}
