<?php

namespace HZ\Illuminate\Mongez\Tests;

use HZ\Illuminate\Mongez\Support\NidKeyRenamer;
use MongoDB\BSON\Document;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use stdClass;

class NidKeyRenamerTest extends TestCase
{
    public function test_it_renames_only_the_top_level_key(): void
    {
        $renamer = new NidKeyRenamer();

        $result = $renamer->rename(['_id' => 1, 'id' => 7, 'name' => 'a']);

        $this->assertSame(1, $result['renamedKeys']);
        $this->assertSame(0, $result['droppedKeys']);
        $this->assertSame(['_id' => 1, 'nid' => 7, 'name' => 'a'], $result['document']);
        $this->assertSame(['id'], $result['paths']);
    }

    public function test_it_never_touches_the_underscore_id(): void
    {
        $renamer = new NidKeyRenamer();
        $oid = new ObjectId();

        $result = $renamer->rename(['_id' => $oid, 'title' => 'x']);

        $this->assertSame(0, $result['renamedKeys']);
        $this->assertSame(['_id' => $oid, 'title' => 'x'], $result['document']);
    }

    public function test_it_renames_nested_objects_and_array_elements(): void
    {
        $renamer = new NidKeyRenamer();

        $result = $renamer->rename([
            '_id' => 1,
            'id' => 5,
            'team' => ['id' => 9, 'name' => 't'],
            'lines' => [
                ['id' => 11, 'qty' => 2],
                ['id' => 12, 'qty' => 3],
            ],
        ]);

        $this->assertSame(4, $result['renamedKeys']);
        $this->assertSame([
            '_id' => 1,
            'nid' => 5,
            'team' => ['nid' => 9, 'name' => 't'],
            'lines' => [
                ['nid' => 11, 'qty' => 2],
                ['nid' => 12, 'qty' => 3],
            ],
        ], $result['document']);
        // paths is a set: both array elements report the same dotted path
        $this->assertSame(['id', 'lines[].id', 'team.id'], $result['paths']);
    }

    public function test_it_keeps_the_existing_nid_and_drops_the_stale_id(): void
    {
        $renamer = new NidKeyRenamer();

        $result = $renamer->rename(['_id' => 1, 'nid' => 5, 'id' => 5]);

        $this->assertSame(0, $result['renamedKeys']);
        $this->assertSame(1, $result['droppedKeys']);
        $this->assertSame(['_id' => 1, 'nid' => 5], $result['document']);
    }

    public function test_it_leaves_skipped_paths_alone_at_any_depth(): void
    {
        $renamer = new NidKeyRenamer(['providerResponse']);

        $result = $renamer->rename([
            '_id' => 1,
            'id' => 5,
            'providerResponse' => ['id' => 'gateway-123', 'status' => 'paid'],
            'order' => ['providerResponse' => ['id' => 'nested']],
        ]);

        $this->assertSame(1, $result['renamedKeys']);
        $this->assertSame(5, $result['document']['nid']);
        $this->assertSame(['id' => 'gateway-123', 'status' => 'paid'], $result['document']['providerResponse']);
        $this->assertSame(['providerResponse' => ['id' => 'nested']], $result['document']['order']);
    }

    public function test_it_preserves_bson_types_and_empty_containers(): void
    {
        $renamer = new NidKeyRenamer();
        $oid = new ObjectId('507f1f77bcf86cd799439011');
        $date = new UTCDateTime(1700000000000);

        $result = $renamer->rename([
            '_id' => $oid,
            'id' => 3,
            'createdAt' => $date,
            'emptyDoc' => new stdClass(),
            'emptyList' => [],
            'nested' => ['emptyDoc' => new stdClass()],
        ]);

        $document = $result['document'];

        $this->assertSame($oid, $document['_id']);
        $this->assertSame($date, $document['createdAt']);
        $this->assertInstanceOf(stdClass::class, $document['emptyDoc']);
        $this->assertSame([], $document['emptyList']);
        $this->assertInstanceOf(stdClass::class, $document['nested']['emptyDoc']);

        // The empty document must re-encode as a BSON {}, not [] — this is the
        // whole reason TYPE_MAP decodes sub-documents as stdClass.
        $extended = Document::fromPHP($result['document'])->toCanonicalExtendedJSON();

        $this->assertStringContainsString('"emptyDoc" : { }', $extended);
        $this->assertStringContainsString('"emptyList" : [ ]', $extended);
    }

    public function test_contains_id_key_ignores_underscore_id_and_skipped_paths(): void
    {
        $renamer = new NidKeyRenamer(['providerResponse']);

        $this->assertFalse($renamer->containsIdKey(['_id' => 1, 'nid' => 2]));
        $this->assertTrue($renamer->containsIdKey(['_id' => 1, 'id' => 2]));
        $this->assertTrue($renamer->containsIdKey(['_id' => 1, 'team' => ['id' => 2]]));
        $this->assertTrue($renamer->containsIdKey(['_id' => 1, 'lines' => [['id' => 2]]]));
        $this->assertFalse($renamer->containsIdKey(['_id' => 1, 'providerResponse' => ['id' => 'x']]));
    }

    public function test_it_ignores_unrelated_keys(): void
    {
        $renamer = new NidKeyRenamer();

        $result = $renamer->rename(['_id' => 1, 'idwe' => 2, 'cid' => 3, 'identity' => 4]);

        $this->assertSame(0, $result['renamedKeys']);
        $this->assertSame(['_id' => 1, 'idwe' => 2, 'cid' => 3, 'identity' => 4], $result['document']);
    }

    public function test_renaming_twice_is_a_no_op(): void
    {
        $renamer = new NidKeyRenamer();

        $first = $renamer->rename(['_id' => 1, 'team' => ['id' => 9]]);
        $second = $renamer->rename($first['document']);

        $this->assertSame(0, $second['renamedKeys']);
        $this->assertSame($first['document'], $second['document']);
    }
}
