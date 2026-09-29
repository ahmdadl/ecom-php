/**
 * One-time migration: business key `id` -> `nid` + mongez `ids` counter rebuild.
 *
 * - Phase 0: preflight inventory (collections, doc counts, nested `.id` paths)
 * - Phase 1: deep-rename key `id` -> `nid` at EVERY nesting/array level
 *            (never touches `_id`; leaves `idwe`/`cid`/other keys alone)
 * - Phase 2: rebuild `ids` counters per collection:
 *            canonical row = max(existing counter value (id/cid/idwe), data max nid across base + `{coll}Trash`)
 *            deletes duplicate/junk counter rows, adds unique index on {collection:1}
 * - Phase 2.5: index management — drop old `id` indexes, create unique `nid` indexes
 * - Phase 3: verification (no surviving `id` keys, counters >= max nid)
 *
 * DRY RUN by default. To apply writes: NID_EXECUTE=1
 *
 * Usage (targets whatever DB is in the URI):
 *   mongodump --db zamil --out /tmp/zamil-dump
 *   mongorestore --nsFrom='zamil.*' --nsTo='zamil-test.*' --drop /tmp/zamil-dump/zamil
 *   mongosh "mongodb://127.0.0.1:27017/zamil-test" --file scripts/mongo-nid-migration.js            # dry run
 *   NID_EXECUTE=1 mongosh "mongodb://127.0.0.1:27017/zamil-test" --file scripts/mongo-nid-migration.js
 */

'use strict';

const EXECUTE = process.env.NID_EXECUTE === '1';
const BATCH_SIZE = parseInt(process.env.NID_BATCH || '500', 10);
const INT32_MAX = 2147483647;

const SKIP_EXACT = new Set([
    'ids',
    'migrations',
    'sessions',
    'jobs',
    'failed_jobs',
    'job_batches',
    'queue_records',
    'cache',
    'cache_locks',
    'personal_access_tokens',
    'password_resets',
]);
const SKIP_PREFIXES = ['oauth_', 'system.'];

// Dotted-path segments whose nested `id` keys must NOT be renamed (opaque
// third-party payloads, e.g. raw payment-provider responses stored inside
// orders/transactions). Matched per path segment AFTER stripping array `[]`
// markers, so 'providerResponse' skips `providerResponse.id` at any depth.
// Phase 3 verification honors the same list. Empty by default — add entries
// only after confirming the field is not a business key.
// const SKIP_ID_PATHS = ['providerResponse', 'gatewayResponse', 'rawPayload'];
const SKIP_ID_PATHS = [];

function isSkippedPath(dotted) {
    const segs = dotted.split('.').map(s => s.replace(/\[\]$/, ''));
    return SKIP_ID_PATHS.some(s => segs.includes(s));
}

const MIGRATION_INDEX_CONFIG = {
    bankpromos: {
        nidIndexes: [
            { keys: { nid: 1 }, name: 'nid_1', unique: true }
        ],
        additionalIndexes: [
            { keys: { published: 1 }, name: 'published_1', unique: false },
            { keys: { bins: 1 }, name: 'bins_1', unique: false }
        ]
    },
    customers: {
        nidIndexes: [
            { keys: { nid: 1 }, name: 'nid_1', unique: true }
        ],
        additionalIndexes: [
            { keys: { phoneNumber: 1 }, name: 'phoneNumber_1', unique: true }
        ]
    },
    holidays: {
        nidIndexes: [
            { keys: { nid: 1 }, name: 'nid_1', unique: true }
        ],
        additionalIndexes: [
            { keys: { date: 1, published: 1 }, name: 'date_published_1', unique: false }
        ]
    },
    installationteamcapacities: {
        nidIndexes: [
            { keys: { nid: 1 }, name: 'nid_1', unique: true },
            { keys: { 'installationTeam.nid': 1, 'city.nid': 1 }, name: 'installationTeam_nid_city_nid_1', unique: true },
            { keys: { 'city.nid': 1, unitsPerDay: 1 }, name: 'city_nid_unitsPerDay_1', unique: true }
        ],
        additionalIndexes: []
    },
    installationteamusages: {
        nidIndexes: [
            { keys: { nid: 1 }, name: 'nid_1', unique: true },
            { keys: { 'installationTeam.nid': 1, 'city.nid': 1, date: 1 }, name: 'installationTeam_nid_city_nid_date_1', unique: true }
        ],
        additionalIndexes: []
    },
    orders: {
        nidIndexes: [
            { keys: { nid: 1 }, name: 'nid_1', unique: true }
        ],
        additionalIndexes: [
            { keys: { idempotencyKey: 1 }, name: 'idempotencyKey_1', unique: true, sparse: true },
            { keys: { transactionId: 1 }, name: 'transactionId_1', unique: false },
            { keys: { source: 1 }, name: 'source_1', unique: false }
        ]
    },
    warehouseusages: {
        nidIndexes: [
            { keys: { nid: 1 }, name: 'nid_1', unique: true },
            { keys: { 'warehouse.nid': 1, 'city.nid': 1, date: 1 }, name: 'warehouse_nid_city_nid_date_1', unique: true }
        ],
        additionalIndexes: []
    },
    courier: {
        nidIndexes: [],
        additionalIndexes: [
            { keys: { code: 1, published: 1 }, name: 'code_published_1', unique: false }
        ]
    }
};

const dbName = db.getName();
const report = { collections: [], renamedDocs: 0, renamedKeys: 0, collisions: 0, counters: [], junkDeleted: 0 };

function log(msg) {
    print(msg);
}

// ---------------------------------------------------------------------------
// Deep transform: rename key 'id' -> 'nid' recursively through objects/arrays
// ---------------------------------------------------------------------------

function isBsonLeaf(v) {
    if (v instanceof Date || v instanceof RegExp) return true;
    return v !== null && typeof v === 'object' && v._bsontype !== undefined;
}

function transform(value, stats, path, pathsOut) {
    if (Array.isArray(value)) {
        const out = new Array(value.length);
        for (let i = 0; i < value.length; i++) out[i] = transform(value[i], stats, path + '[]', pathsOut);
        return out;
    }
    if (value && typeof value === 'object' && !isBsonLeaf(value)) {
        const out = {};
        for (const k of Object.keys(value)) {
            const childPath = path ? path + '.' + k : k;
            if (k === '_id') {
                out._id = value._id;
                continue;
            }
            if (k === 'id') {
                if (isSkippedPath(childPath)) {
                    out[k] = transform(value[k], stats, childPath, pathsOut);
                    continue;
                }
                pathsOut.add(childPath);
                if (Object.prototype.hasOwnProperty.call(value, 'nid')) {
                    // Already migrated: keep the existing nid, drop the stale id.
                    stats.collisions++;
                    continue;
                }
                stats.keysRenamed++;
                out.nid = transform(value[k], stats, childPath.replace(/\.id$/, '.nid'), pathsOut);
                continue;
            }
            out[k] = transform(value[k], stats, childPath, pathsOut);
        }
        return out;
    }
    return value;
}

// Scan-only variant used by verification: returns true if any `id` key survives
// (honors SKIP_ID_PATHS, same as the rename pass)
function hasIdKey(value, path) {
    path = path || '';
    if (Array.isArray(value)) return value.some(v => hasIdKey(v, path + '[]'));
    if (!value || typeof value !== 'object' || isBsonLeaf(value)) return false;
    for (const k of Object.keys(value)) {
        const childPath = path ? path + '.' + k : k;
        if (k === 'id' && !isSkippedPath(childPath)) return true;
        if (hasIdKey(value[k], childPath)) return true;
    }
    return false;
}

// ---------------------------------------------------------------------------
// Phase 0: inventory
// ---------------------------------------------------------------------------

log('=== MongoDB nid migration ===');
log('DB: ' + dbName + ' | MODE: ' + (EXECUTE ? 'EXECUTE' : 'DRY RUN'));
if (!EXECUTE) log('(dry run — no writes; set NID_EXECUTE=1 to apply)');

const allNames = db.getCollectionNames().filter(n => !SKIP_EXACT.has(n) && !SKIP_PREFIXES.some(p => n.startsWith(p)));
const trashNames = new Set(allNames.filter(n => n.endsWith('Trash')));
const baseNames = allNames.filter(n => !trashNames.has(n));

log('\n--- Phase 0: inventory ---');
for (const name of baseNames) {
    const count = db.getCollection(name).countDocuments({});
    const withTopLevelId = db.getCollection(name).countDocuments({ id: { $exists: true } });
    report.collections.push({ name, count });
    log(`  ${name}: ${count} docs (${withTopLevelId} with top-level id)`);
}

// ---------------------------------------------------------------------------
// Phase 1: deep rename id -> nid
// ---------------------------------------------------------------------------

log('\n--- Phase 1: deep rename id -> nid ---');

for (const name of baseNames) {
    const coll = db.getCollection(name);
    let scanned = 0;
    let pendingOps = [];
    const pathsOut = new Set();

    const flush = function() {
        if (!pendingOps.length) return;
        if (EXECUTE) coll.bulkWrite(pendingOps, { ordered: false });
        pendingOps = [];
    };

    const cursor = coll.find({}).sort({ _id: 1 });
    while (cursor.hasNext()) {
        const doc = cursor.next();
        scanned++;
        const stats = { keysRenamed: 0, collisions: 0 };
        const next = transform(doc, stats, '', pathsOut);
        if (stats.keysRenamed > 0) {
            report.renamedKeys += stats.keysRenamed;
            report.collisions += stats.collisions;
            report.renamedDocs++;
            if (EXECUTE) pendingOps.push({ replaceOne: { filter: { _id: doc._id }, replacement: next } });
            if (pendingOps.length >= BATCH_SIZE) flush();
        }
        if (scanned % 5000 === 0) log(`  ${name}: scanned ${scanned}...`);
    }
    flush();

    const pathList = [...pathsOut].sort();
    report.collections.find(c => c.name === name).nestedPaths = pathList;
    log(`  ${name}: scanned=${scanned} docsChanged=${EXECUTE ? '(applied)' : 'would-change'} nestedPaths=[${pathList.join(', ') || '-'}]`);
}

// ---------------------------------------------------------------------------
// Phase 2: ids counter reconciliation + backfill
// ---------------------------------------------------------------------------

log('\n--- Phase 2: ids counters ---');
const idsColl = db.ids;

function toMongoInt(n) {
    return n <= INT32_MAX ? Int32(n) : Long.fromNumber(n);
}

// numeric max tolerating malformed counter rows ({cid}/{idwe} variants)
function counterMaxFor(collectionName) {
    let max = 0;
    for (const row of idsColl.find({ collection: collectionName }).toArray()) {
        for (const f of ['id', 'cid', 'idwe']) {
            const v = Number(row[f]);
            if (Number.isFinite(v) && v > max) max = v;
        }
    }
    return max;
}

function dataMaxNid(collectionName) {
    const names = [collectionName];
    const trash = collectionName + 'Trash';
    if (trashNames.has(trash)) names.push(trash);
    let max = 0;
    for (const n of names) {
        const res = db.getCollection(n).aggregate([{ $group: { _id: null, m: { $max: '$nid' } } }]).toArray();
        const m = Number(res[0]?.m ?? 0);
        if (Number.isFinite(m) && m > max) max = m;
    }
    return max;
}

// junk rows first: no-collection rows are orphans (e.g. the legacy collection:"" pair)
const junkQuery = { $or: [{ collection: '' }, { collection: { $exists: false } }] };
report.junkDeleted = idsColl.countDocuments(junkQuery);
if (report.junkDeleted > 0) log(`  junk counter rows (collection empty/missing): ${report.junkDeleted} -> deleted`);
if (EXECUTE && report.junkDeleted > 0) idsColl.deleteMany(junkQuery);

for (const name of baseNames) {
    const cMax = counterMaxFor(name);
    const dMax = dataMaxNid(name);
    const final = Math.max(cMax, dMax);
    const dupes = idsColl.countDocuments({ collection: name }) ;
    report.counters.push({ collection: name, counterMax: cMax, dataMax: dMax, final });

    log(`  ${name}: counterMax=${cMax} dataMax=${dMax} -> ${final}${dupes > 1 ? ` (${dupes} dup rows collapsed)` : ''}`);
    if (EXECUTE) {
        idsColl.deleteMany({ collection: name });
        if (final > 0) idsColl.insertOne({ collection: name, id: toMongoInt(final) });
    }
}

if (EXECUTE) {
    idsColl.createIndex({ collection: 1 }, { unique: true });
    log('  unique index created on ids.collection');
}

// ---------------------------------------------------------------------------
// Phase 2.5: index management — drop old id indexes, create new nid indexes
// ---------------------------------------------------------------------------

log('\n--- Phase 2.5: index management ---');

function getMigrationIndexInfo(collectionName) {
    if (Object.prototype.hasOwnProperty.call(MIGRATION_INDEX_CONFIG, collectionName)) {
        return MIGRATION_INDEX_CONFIG[collectionName];
    }
    // Unlisted collections default to a unique nid_1 (create + verify agree).
    // An explicit empty nidIndexes list (e.g. courier) opts out of nid_1.
    return { nidIndexes: [{ keys: { nid: 1 }, name: 'nid_1', unique: true }], additionalIndexes: [] };
}

function getAdditionalIndexes(collectionName) {
    return MIGRATION_INDEX_CONFIG[collectionName]?.additionalIndexes || [];
}

function getIndexNames(coll) {
    return coll.getIndexes().map(i => i.name);
}

function hasIndexNamed(coll, name) {
    return getIndexNames(coll).includes(name);
}

function dropIndexesFromMigration(coll, migrationInfo) {
    if (!migrationInfo || !migrationInfo.idIndexes || !migrationInfo.idIndexes.length) {
        return;
    }
    for (const indexSpec of migrationInfo.idIndexes) {
        const indexName = indexSpec.name || `${indexSpec.keys}_1`;
        try {
            coll.dropIndex(indexName);
            log(`  ${coll.getName()}: dropped index ${indexName}`);
        } catch (e) {
            log(`  ${coll.getName()}: no ${indexName} index to drop (already removed)`);
        }
    }
}

function dropIndexesFromPhase2(coll) {
    const idIndexName = 'id_1';
    try {
        coll.dropIndex(idIndexName);
        log(`  ${coll.getName()}: dropped index ${idIndexName}`);
    } catch (e) {
        log(`  ${coll.getName()}: no id_1 index to drop (already removed)`);
    }
}

function createIndexesFromMigration(coll, migrationInfo) {
    if (!migrationInfo || !migrationInfo.nidIndexes || !migrationInfo.nidIndexes.length) {
        return;
    }
    for (const indexSpec of migrationInfo.nidIndexes) {
        const indexName = indexSpec.name || `${indexSpec.keys}_1`;
        if (!hasIndexNamed(coll, indexName)) {
            try {
                coll.createIndex(indexSpec.keys, { unique: indexSpec.unique ?? true, name: indexName });
                log(`  ${coll.getName()}: created unique index ${indexName}`);
            } catch (e) {
                log(`  ${coll.getName()}: FAILED to create nid_1 index — ${e}`);
            }
        }
    }
}

function createAdditionalIndexesFromMigration(coll, additionalIndexes) {
    if (!additionalIndexes || !additionalIndexes.length) {
        return;
    }
    for (const indexSpec of additionalIndexes) {
        const indexName = indexSpec.name || `${JSON.stringify(indexSpec.keys).replace(/[{}]/g, '').replace(/:/g, '_').replace(/, /g, '_')}_1`;
        if (!hasIndexNamed(coll, indexName)) {
            try {
                // Pass `name` through so the created index matches verification
                const { keys, ...indexOptions } = indexSpec;
                coll.createIndex(indexSpec.keys, { ...indexOptions, name: indexName });
                log(`  ${coll.getName()}: created index ${indexName}`);
            } catch (e) {
                log(`  ${coll.getName()}: FAILED to create index ${indexName} — ${e}`);
            }
        }
    }
}

if (EXECUTE) {
    // Drop all id indexes first based on migration analysis
    for (const name of baseNames) {
        const coll = db.getCollection(name);
        dropIndexesFromPhase2(coll);
    }
    // Also clean indexes on the ids collection
    if (hasIndexNamed(idsColl, 'id_1')) {
        idsColl.dropIndex('id_1');
        log(`  ids: dropped index id_1`);
    }

    // Create new indexes based on migration analysis
    for (const name of baseNames) {
        const coll = db.getCollection(name);
        const migrationInfo = getMigrationIndexInfo(name);
        
        // Drop old migration-based indexes (id versions)
        if (migrationInfo.idIndexes && migrationInfo.idIndexes.length > 0) {
            dropIndexesFromMigration(coll, migrationInfo);
        }
        
        // Create new nid-based indexes
        if (migrationInfo.nidIndexes && migrationInfo.nidIndexes.length > 0) {
            createIndexesFromMigration(coll, migrationInfo);
        }
        
        // Create additional indexes from migration
        const additionalIndexes = getAdditionalIndexes(name);
        if (additionalIndexes && additionalIndexes.length > 0) {
            createAdditionalIndexesFromMigration(coll, additionalIndexes);
        }
    }
}

// ---------------------------------------------------------------------------
// Phase 3: verification
// ---------------------------------------------------------------------------

log('\n--- Phase 3: verification ---');
let failures = 0;

if (!EXECUTE) {
    log('(skipped in dry run — apply with NID_EXECUTE=1, then re-run to verify)');
} else {

for (const { name, count } of report.collections) {
    let leftover = 0;
    const cursor = db.getCollection(name).find({});
    while (cursor.hasNext()) {
        if (hasIdKey(cursor.next())) leftover++;
        if (leftover >= 5) break;
    }
    if (leftover > 0) {
        failures++;
        log(`  FAIL ${name}: still has docs with 'id' keys`);
    }
}

for (const c of report.counters) {
    if (c.final === 0) continue; // empty collection: no counter row required
    const row = idsColl.findOne({ collection: c.collection });
    if (!row || Number(row.id) < c.final) {
        failures++;
        log(`  FAIL ids[${c.collection}]: expected >= ${c.final}, got ${row ? row.id : '<missing>'}`);
    }
}

// Index verification (dry-run safe)
for (const name of baseNames) {
    const coll = db.getCollection(name);
    const migrationInfo = getMigrationIndexInfo(name);
    const additionalIndexes = getAdditionalIndexes(name);
    
    // Old id indexes must be gone everywhere. Required nid indexes are
    // verified per-collection below via getMigrationIndexInfo (unlisted
    // collections default to unique nid_1; an explicit empty nidIndexes
    // list, e.g. courier, is exempt).
    const oldIdx = coll.getIndexes().find(i => i.name === 'id_1');
    if (oldIdx) {
        failures++;
        log(`  FAIL ${name}: old id_1 index still present`);
    }
    
    // Verify migration-based indexes exist
    if (migrationInfo.nidIndexes && migrationInfo.nidIndexes.length > 0) {
        for (const indexSpec of migrationInfo.nidIndexes) {
            const indexName = indexSpec.name || `${indexSpec.keys}_1`;
            const indexExists = coll.getIndexes().find(i => i.name === indexName);
            if (!indexExists) {
                failures++;
                log(`  FAIL ${name}: ${indexName} index missing`);
            }
        }
    }
    
    // Verify additional indexes exist
    if (additionalIndexes && additionalIndexes.length > 0) {
        for (const indexSpec of additionalIndexes) {
            const indexName = indexSpec.name || `${JSON.stringify(indexSpec.keys).replace(/[{}]/g, '').replace(/:/g, '_').replace(/, /g, '_')}_1`;
            const indexExists = coll.getIndexes().find(i => i.name === indexName);
            if (!indexExists) {
                failures++;
                log(`  FAIL ${name}: ${indexName} index missing`);
            }
        }
    }
}

} // end else (EXECUTE) — verification runs only on applied state

log('\n--- Summary ---');
log(`docs changed : ${report.renamedDocs}${EXECUTE ? '' : ' (would-change, dry run)'}`);
log(`keys renamed : ${report.renamedKeys} (collisions kept-nid: ${report.collisions})`);
log(`collections  : ${report.collections.length}`);
log(`counters set : ${report.counters.length}`);
log(junkDeletedLabel());
log(!EXECUTE ? 'RESULT: DRY RUN (no writes, verification skipped)' : (failures === 0 ? 'RESULT: OK' : `RESULT: ${failures} FAILURES`));

function junkDeletedLabel() {
    return `junk removed : ${report.junkDeleted}`;
}
