<?php

namespace HZ\Illuminate\Mongez\Tests;

use HZ\Illuminate\Mongez\Support\NidKeyRenamer;
use Illuminate\Support\Facades\Artisan;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Client;

class NidSyncIntegrationTest extends TestCase
{
    private string $oldUri = 'mongodb://127.0.0.1:27017/mongez_test_nid_sync_old';
    private string $newUri = 'mongodb://127.0.0.1:27017/mongez_test_nid_sync_new';

    private Client $oldClient;
    private Client $newClient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->oldClient = new Client($this->oldUri);
        $this->newClient = new Client($this->newUri);
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        foreach ([$this->oldClient->selectDatabase('mongez_test_nid_sync_old'), $this->newClient->selectDatabase('mongez_test_nid_sync_new')] as $db) {
            foreach (['widgets','gadgets','ids'] as $c) {
                try { $db->dropCollection($c); } catch (\Throwable $e) {}
            }
        }
        @unlink(sys_get_temp_dir().'/nid-manifest-test.json');
        @unlink(sys_get_temp_dir().'/nid-manifest-test2.json');
    }

    private function oldDb(): \MongoDB\Database
    {
        return $this->oldClient->selectDatabase('mongez_test_nid_sync_old');
    }

    private function newDb(): \MongoDB\Database
    {
        return $this->newClient->selectDatabase('mongez_test_nid_sync_new');
    }

    private function readManifest(string $path): array
    {
        $contents = file_get_contents($path);
        $this->assertNotFalse($contents, "manifest not readable: {$path}");
        $manifest = json_decode($contents, true);
        $this->assertIsArray($manifest, "manifest not valid JSON: {$path}");

        return $manifest;
    }

    public function test_snapshot_captures_manifest(): void
    {
        $this->oldDb()->selectCollection('widgets')->insertOne(['_id'=>new ObjectId(),'id'=>10,'name'=>'alpha','updatedAt'=> new UTCDateTime((int)(microtime(true)*1000))]);
        $this->oldDb()->selectCollection('ids')->insertOne(['collection'=>'widgets','id'=>10]);

        $out = sys_get_temp_dir().'/nid-manifest-test.json';
        $exit = Artisan::call('mongez:nid-snapshot', ['--uri'=>$this->oldUri, '--out'=>$out, '--collection'=>['widgets']]);
        $this->assertSame(0, $exit);
        $this->assertFileExists($out);
        $manifest = $this->readManifest($out);
        $this->assertArrayHasKey('generatedAt', $manifest);
        $this->assertArrayHasKey('collections', $manifest);
        $this->assertArrayHasKey('widgets', $manifest['collections']);
        $this->assertSame(1, $manifest['collections']['widgets']['count']);
        $this->assertSame(10, $manifest['collections']['widgets']['maxId']);
        $this->assertFileExists($out);
    }

    public function test_forward_sync_with_watermark_and_reverse(): void
    {
        // Seed old with 2 legacy docs
        $oldDb = $this->oldDb();
        $newDb = $this->newDb();

        $oldDb->selectCollection('widgets')->insertOne(['_id'=>new ObjectId(), 'id'=>10, 'name'=>'alpha', 'team'=>['id'=>77], 'updatedAt'=> new UTCDateTime((int)(microtime(true)*1000))]);
        usleep(20000);
        $oldDb->selectCollection('widgets')->insertOne(['_id'=>new ObjectId(), 'id'=>11, 'name'=>'beta', 'team'=>['id'=>78], 'updatedAt'=> new UTCDateTime((int)(microtime(true)*1000))]);
        $oldDb->selectCollection('ids')->insertOne(['collection'=>'widgets','id'=>11]);

        // snapshot at T0
        $out = sys_get_temp_dir().'/nid-manifest-test.json';
        Artisan::call('mongez:nid-snapshot', ['--uri'=>$this->oldUri, '--out'=>$out, '--collection'=>['widgets','ids']]);
        $manifest = $this->readManifest($out);
        $generatedAt = $manifest['generatedAt'];

        // clone to new
        foreach (['widgets','ids'] as $col) {
            $docs = $oldDb->selectCollection($col)->find([], ['typeMap'=>['root'=>'array','document'=>'array','array'=>'array']])->toArray();
            if ($docs !== []) $newDb->selectCollection($col)->insertMany(array_values($docs));
        }

        // migrate new to nid
        $renamer = new NidKeyRenamer();
        foreach ($newDb->selectCollection('widgets')->find([], ['typeMap'=> NidKeyRenamer::TYPE_MAP]) as $doc) {
            if (!is_array($doc)) continue;
            $res = $renamer->rename($doc);
            if ($res['renamedKeys']||$res['droppedKeys']) {
                $newDb->selectCollection('widgets')->replaceOne(['_id'=>$doc['_id']], $res['document']);
            }
        }
        // collapse ids in new
        $newDb->selectCollection('ids')->deleteMany([]);
        $newDb->selectCollection('ids')->insertOne(['collection'=>'widgets','id'=>11]);

        // delta in old after clone: new doc id=12
        usleep(20000);
        $oldDb->selectCollection('widgets')->insertOne(['_id'=>new ObjectId(), 'id'=>12, 'name'=>'gamma', 'team'=>['id'=>79], 'updatedAt'=> new UTCDateTime((int)(microtime(true)*1000))]);
        $oldDb->selectCollection('ids')->deleteMany(['collection'=>'widgets']);
        $oldDb->selectCollection('ids')->insertOne(['collection'=>'widgets','id'=>12]);

        // forward sync old->new using manifest watermark
        $exit = Artisan::call('mongez:nid-sync', [
            '--from-uri'=>$this->oldUri,
            '--to-uri'=>$this->newUri,
            '--direction'=>'forward',
            '--manifest'=>$out,
            '--collection'=>['widgets'],
            '--execute'=>true,
        ]);
        $this->assertSame(0, $exit, Artisan::output());

        // verify new has gamma with nid
        $gamma = $newDb->selectCollection('widgets')->findOne(['name'=>'gamma'], ['typeMap'=>['root'=>'array','document'=>'array']]);
        $this->assertIsArray($gamma);
        $this->assertSame(12, $gamma['nid']);
        $this->assertArrayNotHasKey('id', $gamma);
        $this->assertSame(79, $gamma['team']['nid']);

        $ids = $newDb->selectCollection('ids')->findOne(['collection'=>'widgets'], ['typeMap'=>['root'=>'array','document'=>'array']]);
        $this->assertIsArray($ids);
        $this->assertSame(12, (int)$ids['id'], 'ids merged to max');

        // reverse: add new nid doc to new
        $newDb->selectCollection('widgets')->insertOne(['_id'=>new ObjectId(), 'nid'=>13, 'name'=>'delta', 'team'=>['nid'=>80], 'updatedAt'=> new UTCDateTime((int)(microtime(true)*1000))]);
        $newDb->selectCollection('ids')->deleteMany(['collection'=>'widgets']);
        $newDb->selectCollection('ids')->insertOne(['collection'=>'widgets','id'=>13]);

        // watermark for reverse: 2 sec ago to capture delta
        $since = (new \DateTimeImmutable('-2 seconds'))->format('Y-m-d\TH:i:s.v\Z');
        $exit2 = Artisan::call('mongez:nid-sync', [
            '--from-uri'=>$this->newUri,
            '--to-uri'=>$this->oldUri,
            '--direction'=>'reverse',
            '--since'=>$since,
            '--collection'=>['widgets'],
            '--execute'=>true,
        ]);
        $this->assertSame(0, $exit2, Artisan::output());

        $deltaOld = $oldDb->selectCollection('widgets')->findOne(['name'=>'delta'], ['typeMap'=>['root'=>'array','document'=>'array']]);
        $this->assertIsArray($deltaOld);
        $this->assertSame(13, $deltaOld['id']);
        $this->assertArrayNotHasKey('nid', $deltaOld);
        $this->assertSame(80, $deltaOld['team']['id']);

        $oldIds = $oldDb->selectCollection('ids')->findOne(['collection'=>'widgets'], ['typeMap'=>['root'=>'array','document'=>'array']]);
        $this->assertIsArray($oldIds);
        $this->assertSame(13, (int)$oldIds['id']);
    }

    public function test_forward_sync_full_without_watermark(): void
    {
        $oldDb = $this->oldDb();
        $newDb = $this->newDb();

        $oldDb->selectCollection('widgets')->insertOne(['_id'=>new ObjectId(), 'id'=>20, 'name'=>'full', 'team'=>['id'=>90], 'updatedAt'=> new UTCDateTime((int)(microtime(true)*1000))]);
        $oldDb->selectCollection('ids')->insertOne(['collection'=>'widgets','id'=>20]);

        // full sync without manifest/since
        $exit = Artisan::call('mongez:nid-sync', [
            '--from-uri'=>$this->oldUri,
            '--to-uri'=>$this->newUri,
            '--direction'=>'forward',
            '--collection'=>['widgets'],
            '--execute'=>true,
        ]);
        $this->assertSame(0, $exit, Artisan::output());
        $doc = $newDb->selectCollection('widgets')->findOne(['name'=>'full'], ['typeMap'=>['root'=>'array','document'=>'array']]);
        $this->assertIsArray($doc);
        $this->assertSame(20, $doc['nid']);
    }
}
