<?php

namespace HZ\Illuminate\Mongez\Tests;

use HZ\Illuminate\Mongez\Database\Eloquent\MongoDB\Database;
use Illuminate\Support\Facades\Artisan;
use MongoDB\BSON\Document;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

class NidMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->dropCollections('widgets', 'gadgets', 'gadgetsTrash', 'ids');
    }

    protected function tearDown(): void
    {
        $this->dropCollections('widgets', 'gadgets', 'gadgetsTrash', 'ids');

        parent::tearDown();
    }

    public function test_dry_run_reports_without_writing(): void
    {
        $this->seedLegacy();

        $exit = $this->migrate();

        $this->assertSame(0, $exit, 'a clean dry run exits 0');

        // Nothing may have moved.
        $this->assertSame(1, $this->countIn('widgets', ['id' => ['$exists' => true]]));
        $this->assertSame(0, $this->countIn('widgets', ['nid' => ['$exists' => true]]));
        $this->assertSame(5, $this->idsRow('widgets'), 'the counter is not advanced in a dry run');
    }

    public function test_execute_renames_nested_ids_and_rebuilds_counters(): void
    {
        $this->seedLegacy();

        $this->assertSame(0, $this->migrate(['--execute' => true]));

        $widget = $this->first('widgets', ['name' => 'alpha']);

        $this->assertSame(42, $widget['nid']);
        $this->assertArrayNotHasKey('id', $widget);
        $this->assertSame(77, $widget['team']['nid']);
        $this->assertArrayNotHasKey('id', $widget['team']);
        $this->assertSame(88, $widget['lines'][0]['nid']);
        $this->assertSame(89, $widget['lines'][1]['nid']);

        // The counter tracks the collection's own top-level nid, which is 42 —
        // the nested nids belong to embedded documents, not to this collection.
        $this->assertSame(42, $this->idsRow('widgets'));

        // Trash data counts toward the counter even when it is not migrated.
        $this->assertSame(200, $this->idsRow('gadgets'));
        $this->assertSame(1, $this->countIn('gadgetsTrash', ['id' => ['$exists' => true]]));
    }

    public function test_execute_creates_the_unique_nid_index_and_drops_the_legacy_one(): void
    {
        $this->seedLegacy();
        $this->database()->selectCollection('widgets')->createIndex(['id' => 1]);

        $this->assertSame(0, $this->migrate(['--execute' => true]));

        $indexes = $this->indexNames('widgets');

        $this->assertContains('nid_1', $indexes);
        $this->assertNotContains('id_1', $indexes);

        $nidIndex = null;

        foreach ($this->database()->selectCollection('widgets')->listIndexes() as $index) {
            if ($index->getName() === 'nid_1') {
                $nidIndex = $index;
            }
        }

        $this->assertNotNull($nidIndex);
        $this->assertTrue($nidIndex->isUnique());
    }

    public function test_ids_collection_gets_a_unique_collection_index(): void
    {
        $this->seedLegacy();

        $this->migrate(['--execute' => true]);

        $this->assertContains('collection_1', $this->indexNames('ids'));
    }

    public function test_duplicate_counter_rows_collapse_to_one(): void
    {
        $this->seedLegacy();
        $ids = $this->database()->selectCollection('ids');
        $ids->insertMany([
            ['collection' => 'widgets', 'id' => 10],
            ['collection' => 'widgets', 'cid' => 20],
        ]);
        $ids->insertOne(['collection' => '']);

        $this->migrate(['--execute' => true]);

        $this->assertSame(1, $this->countIn('ids', ['collection' => 'widgets']));
        $this->assertSame(0, $this->countIn('ids', ['collection' => '']));
        $this->assertSame(42, $this->idsRow('widgets'), 'the highest legacy variant is not lost');
    }

    public function test_counter_never_regresses(): void
    {
        $this->seedLegacy();
        $this->database()->selectCollection('ids')->insertOne(['collection' => 'widgets', 'id' => 5000]);

        $this->migrate(['--execute' => true]);

        $this->assertSame(5000, $this->idsRow('widgets'), 'a counter ahead of the data stays put');
    }

    public function test_duplicate_nid_values_are_reported_and_block_the_index(): void
    {
        $this->seedLegacy();
        $widgets = $this->database()->selectCollection('widgets');
        $widgets->insertOne(['_id' => 2, 'nid' => 42, 'name' => 'clash']);

        $exit = $this->migrate(['--execute' => true]);

        $this->assertSame(1, $exit, 'duplicates must fail the run');

        $this->assertNotContains('nid_1', $this->indexNames('widgets'), 'the index is not created over duplicates');
        $this->assertSame(2, $this->countIn('widgets', ['nid' => 42]), 'no document is ever deleted');
    }

    public function test_top_level_only_leaves_nested_ids_and_the_verify_phase_catches_them(): void
    {
        $this->seedLegacy();

        $this->migrate(['--execute' => true, '--top-level-only' => true]);

        $widget = $this->first('widgets', ['name' => 'alpha']);

        $this->assertSame(42, $widget['nid'], 'the top-level key is renamed');
        $this->assertSame(77, $widget['team']['id'], 'nested keys are left alone');

        $this->assertSame(1, $this->migrate(['--phase' => ['verify']]), 'verify gates on the leftover nested id');
    }

    public function test_skip_paths_preserve_opaque_payloads(): void
    {
        $this->seedLegacy();
        $this->database()->selectCollection('widgets')->insertOne([
            '_id' => 3,
            'id' => 100,
            'name' => 'gamma',
            'providerResponse' => ['id' => 'gateway-abc', 'status' => 'paid'],
        ]);

        $this->assertSame(0, $this->migrate([
            '--execute' => true,
            '--skip-path' => ['providerResponse'],
        ]));

        $gamma = $this->first('widgets', ['name' => 'gamma']);

        $this->assertSame(100, $gamma['nid']);
        $this->assertSame('gateway-abc', $gamma['providerResponse']['id'], 'the opaque payload is untouched');
    }

    public function test_running_twice_is_idempotent(): void
    {
        $this->seedLegacy();

        $this->migrate(['--execute' => true]);
        $after = $this->first('widgets', ['name' => 'alpha']);

        $this->assertSame(0, $this->migrate(['--execute' => true]), 'the second run is a clean no-op');
        $this->assertSame($after, $this->first('widgets', ['name' => 'alpha']));
        $this->assertSame(1, $this->countIn('ids', ['collection' => 'widgets']));
    }

    public function test_preserves_bson_types_through_the_rewrite(): void
    {
        $oid = new ObjectId();
        $date = new UTCDateTime(1700000000000);
        $this->database()->selectCollection('gadgets')->insertOne([
            '_id' => $oid,
            'id' => 5,
            'createdAt' => $date,
            'emptyDoc' => Document::fromPHP([]),
            'tags' => ['a', 'b'],
        ]);

        $this->migrate(['--execute' => true]);

        $gadget = $this->first('gadgets', []);

        $this->assertSame(5, $gadget['nid']);
        $this->assertEquals($oid, $gadget['_id']);
        $this->assertEquals($date, $gadget['createdAt']);
        $this->assertSame(['a', 'b'], $gadget['tags']);
    }

    public function test_unknown_phase_is_rejected(): void
    {
        $this->assertSame(1, $this->migrate(['--phase' => ['nope']]));
    }

    public function test_collection_filter_limits_the_run(): void
    {
        $this->seedLegacy();

        $this->migrate(['--execute' => true, '--collection' => ['widgets']]);

        $this->assertSame(1, $this->countIn('gadgets', ['id' => ['$exists' => true]]), 'gadgets is untouched');
        $this->assertSame(0, $this->countIn('gadgets', ['nid' => ['$exists' => true]]));
        $this->assertSame(0, $this->countIn('widgets', ['id' => ['$exists' => true]]), 'widgets is migrated');
    }

    public function test_include_trash_migrates_trash_collections_too(): void
    {
        $this->seedLegacy();

        $this->migrate(['--execute' => true, '--include-trash' => true]);

        $this->assertSame(0, $this->countIn('gadgetsTrash', ['id' => ['$exists' => true]]));
        $this->assertSame(1, $this->countIn('gadgetsTrash', ['nid' => ['$exists' => true]]));
    }

    public function test_dry_run_with_findings_does_not_fail_the_process(): void
    {
        $this->seedLegacy();
        $this->database()->selectCollection('widgets')->insertOne(['_id' => 2, 'nid' => 42, 'name' => 'clash']);

        $this->assertSame(0, $this->migrate(), 'a dry run reports findings but exits 0');
    }

    public function test_verify_only_dry_run_does_gate(): void
    {
        $this->seedLegacy();

        $this->assertSame(1, $this->migrate(['--phase' => ['verify']]), 'a verify-only run gates even without --execute');
    }

    public function test_a_dry_run_does_not_verify_against_its_own_unwritten_work(): void
    {
        $this->seedLegacy();

        // Nothing was renamed, no index was created, no counter moved, so every
        // verify check is reporting the absence of the work this run just
        // declined to do. Echoing that back buries the findings that are real,
        // so verify says it cannot judge rather than listing every collection.
        $this->migrate();
        $output = Artisan::output(); // fetched once: the buffer is drained on read

        $this->assertStringContainsString('nothing was written, so the state cannot be verified', $output);
        $this->assertStringNotContainsString('still carry an `id` key', $output);
        $this->assertStringNotContainsString('required index nid_1 is missing', $output);
        $this->assertStringNotContainsString('ids[widgets]', $output);
    }

    public function test_a_verify_only_dry_run_still_reports_the_real_state(): void
    {
        $this->seedLegacy();

        // The opposite case: nothing else in this invocation was going to
        // change the state, so verify has something true to say.
        $this->migrate(['--phase' => ['verify']]);
        $output = Artisan::output(); // fetched once: the buffer is drained on read

        $this->assertStringNotContainsString('cannot be verified', $output);
        $this->assertStringContainsString('still carry an `id` key', $output);
    }

    public function test_a_collection_with_no_top_level_nid_is_reported_not_blocked(): void
    {
        $this->seedLegacy();
        $this->database()->selectCollection('gadgetsTrash')->deleteMany([]);

        // A trash-shaped collection: keyed by primaryId, identity under record.
        $this->database()->selectCollection('gadgetsTrash')->insertMany([
            ['_id' => 1, 'primaryId' => 7, 'record' => ['nid' => 7, 'name' => 'gone']],
            ['_id' => 2, 'primaryId' => 8, 'record' => ['nid' => 8, 'name' => 'also gone']],
        ]);

        $this->assertSame(0, $this->migrate(['--execute' => true, '--include-trash' => true]));
        $this->assertNotContains('nid_1', $this->indexNames('gadgetsTrash'), 'a unique nid index cannot apply here');
    }

    public function test_a_collection_still_on_id_is_told_to_rename_not_to_opt_out(): void
    {
        $this->seedLegacy();
        $this->database()->selectCollection('gadgets')->insertOne(['_id' => 2, 'id' => 6, 'name' => 'second']);

        // `gadgets` is entirely on `id`, so asked to index it on its own there
        // is no top-level nid to index. That is the same shape as a `*Trash`
        // collection, but the opposite problem: this one is waiting for the
        // rename. Telling the user to opt it out of mongez.nid.indexes would
        // silence a collection that should have been migrated.
        // A dry run, so the exit code is not gated on findings (only --execute
        // and a verify-only run gate). The advice in the report is the point.
        $this->migrate(['--phase' => ['indexes']]);
        $output = Artisan::output(); // fetched once: the buffer is drained on read

        $this->assertStringContainsString('gadgets: cannot create nid_1', $output);
        $this->assertStringContainsString('still on `id`', $output);
        $this->assertStringNotContainsString('opt this collection out', $output);
    }

    public function test_a_partially_identified_collection_still_blocks(): void
    {
        $this->seedLegacy();
        $widgets = $this->database()->selectCollection('widgets');
        $widgets->insertOne(['_id' => 2, 'nid' => 43, 'name' => 'has one']);
        $widgets->insertOne(['_id' => 3, 'name' => 'has none']);
        $widgets->insertOne(['_id' => 4, 'name' => 'nor this one']);

        // Neither orphan carries an `id`, so the rename has nothing to move
        // across for them and they will still collide on the unique index.
        $this->assertSame(1, $this->migrate(['--execute' => true]), 'a real gap still fails the run');
    }

    public function test_a_collection_still_on_id_is_not_reported_as_a_gap(): void
    {
        $this->seedLegacy();
        $widgets = $this->database()->selectCollection('widgets');
        $widgets->insertOne(['_id' => 2, 'nid' => 43, 'name' => 'has one']);
        $widgets->insertOne(['_id' => 3, 'id' => 44, 'name' => 'still on id']);

        // The seeded widget and this one are both on `id`, so before the
        // rename the collection looks half identified. Judged on the stored
        // state that reads as a gap; judged on the state the run ends in it
        // is clean, and blocking here would fail a migration that succeeds.
        $this->assertSame(0, $this->migrate(['--execute' => true]));
        $this->assertSame(44, $this->first('widgets', ['nid' => 44])['nid']);
    }

    public function test_the_inventory_phase_alone_reports_the_whole_migration(): void
    {
        $this->seedLegacy();
        $widgets = $this->database()->selectCollection('widgets');
        $widgets->insertOne(['_id' => 2, 'nid' => 43, 'name' => 'has one']);
        $widgets->insertOne(['_id' => 3, 'id' => 44, 'name' => 'still on id']);

        // `--phase=inventory` is a pre-flight report, so it must describe the
        // migration the user is about to run rather than the state stored right
        // now. Judged on the stored state it calls the two `id` documents a gap
        // and fails, which is a different verdict from the full run — that one
        // renames them and succeeds.
        $this->assertSame(0, $this->migrate(['--phase' => ['inventory']]));
        $this->assertStringNotContainsString('will still carry no nid', Artisan::output());
    }

    public function test_a_dry_run_reports_duplicates_among_the_ids_about_to_become_nids(): void
    {
        $this->seedLegacy();
        $widgets = $this->database()->selectCollection('widgets');
        $widgets->insertOne(['_id' => 2, 'id' => 42, 'name' => 'collides with alpha']);
        $widgets->insertMany([
            ['_id' => 3, 'id' => 88, 'name' => 'one'],
            ['_id' => 4, 'id' => 88, 'name' => 'two'],
        ]);

        // Nothing carries `nid` yet, so a dry run that only looked at the
        // stored field would report a clean database and let the collision
        // reach the unique index, which then refuses to build.
        $exit = $this->migrate(['--execute' => true]);
        $output = Artisan::output(); // fetched once: the buffer is drained on read

        $this->assertSame(1, $exit, 'the collision is caught before the index is attempted');
        $this->assertStringContainsString('nid=42 x2', $output);
        $this->assertStringContainsString('nid=88 x2', $output);
    }

    public function test_a_non_unique_nid_index_is_replaced_by_the_unique_one(): void
    {
        $this->seedLegacy();
        $this->database()->selectCollection('widgets')->createIndex(['nid' => 1]);

        $this->assertSame(0, $this->migrate(['--execute' => true]));

        $nid = null;

        foreach ($this->database()->selectCollection('widgets')->listIndexes() as $index) {
            if ($index->getName() === 'nid_1') {
                $nid = $index;
            }
        }

        $this->assertNotNull($nid, 'the index name is reused');
        $this->assertTrue($nid->isUnique(), 'a leftover non-unique index is not accepted as the unique one');
    }

    public function test_verify_catches_a_non_unique_nid_index(): void
    {
        $this->seedLegacy();
        $this->database()->selectCollection('widgets')->insertOne(['_id' => 2, 'nid' => 43, 'name' => 'beta']);
        $this->database()->selectCollection('widgets')->createIndex(['nid' => 1]);

        $this->assertSame(1, $this->migrate(['--phase' => ['verify']]));
        $this->assertStringContainsString('widgets: nid_1 is not unique', Artisan::output());
    }

    /**
     * Two collections in the legacy `id` scheme, with nested and array keys,
     * a *Trash sibling, and stale duplicate/junk rows in `ids`.
     */
    protected function seedLegacy(): void
    {
        $this->database()->selectCollection('widgets')->insertOne([
            '_id' => 1,
            'id' => 42,
            'name' => 'alpha',
            'team' => ['id' => 77, 'title' => 'core'],
            'lines' => [
                ['id' => 88, 'qty' => 1],
                ['id' => 89, 'qty' => 2],
            ],
        ]);

        $this->database()->selectCollection('gadgets')->insertOne(['_id' => 1, 'id' => 5, 'name' => 'one']);
        $this->database()->selectCollection('gadgetsTrash')->insertOne(['_id' => 1, 'id' => 200, 'name' => 'gone']);

        $this->database()->selectCollection('ids')->insertMany([
            ['collection' => 'widgets', 'id' => 5],
            ['collection' => 'gadgets', 'id' => 1],
        ]);
    }

    /**
     * Run the command against this test's own collections only, so unrelated
     * fixtures left in the shared test database cannot affect the exit code.
     *
     * @param  array<string, mixed>  $options
     */
    protected function migrate(array $options = []): int
    {
        // gadgetsTrash is listed so the --include-trash test has a target; the
        // command filters *Trash out again unless that flag is passed.
        $options['--collection'] ??= ['widgets', 'gadgets', 'gadgetsTrash'];

        return Artisan::call('mongez:migrate-nid', $options);
    }

    protected function database(): \MongoDB\Database
    {
        return Database::getDatabase();
    }

    /**
     * @param  array<string, mixed>  $filter
     * @return array<string, mixed>
     */
    protected function first(string $collection, array $filter): array
    {
        $document = $this->database()
            ->selectCollection($collection)
            ->findOne($filter, ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']]);

        $this->assertIsArray($document, "expected a document in {$collection}");

        return $document;
    }

    /**
     * @param  array<string, mixed>  $filter
     */
    protected function countIn(string $collection, array $filter): int
    {
        return $this->database()->selectCollection($collection)->countDocuments($filter);
    }

    /**
     * The stored counter value for a collection.
     */
    protected function idsRow(string $collection): int
    {
        $row = $this->database()
            ->selectCollection('ids')
            ->findOne(['collection' => $collection], ['typeMap' => ['root' => 'array', 'document' => 'array']]);

        $this->assertIsArray($row, "expected an ids row for {$collection}");
        $this->assertArrayHasKey('id', $row);

        return (int) $row['id'];
    }

    /**
     * @return list<string>
     */
    protected function indexNames(string $collection): array
    {
        $names = [];

        foreach ($this->database()->selectCollection($collection)->listIndexes() as $index) {
            $names[] = $index->getName();
        }

        return $names;
    }
}
