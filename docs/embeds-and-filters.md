# Embedded documents and list filters

Helpers for denormalized Mongo embeds (`associate` / `reassociate` /
`sharedInfo`) and common admin `FILTER_BY` patterns.

## Partial embed patch

Update one element in an embedded array without rewriting siblings:

```php
$order->patchEmbedded('items', $itemNid, [
    'quantity' => 3,
    'status' => 'picked',
])->save();

// Match by criteria instead of nid
$order->patchEmbedded('items', ['sku' => 'ABC-1'], ['quantity' => 3]);

// Append when missing
$order->patchEmbedded('items', 99, ['nid' => 99, 'sku' => 'NEW'], createIfMissing: true);
```

Repository wrapper:

```php
repo('orders')->patchEmbedded($orderId, 'items', $itemNid, ['quantity' => 3]);
```

This path goes through Eloquent `save()`, so model cache invalidation and
`ModelEvents` related sync still apply. Mass query-builder / `arrayFilters`
updates do **not** fire Eloquent events — call `invalidateCacheByIds()` (or
`invalidateAll()`) yourself after those writes.

## Refresh one sharedInfo snapshot

```php
// Singular embed (object)
$order->refreshEmbeddedSharedInfo('customer', $customer)->save();

// List embed (replace matching nid element, or append)
$order->refreshEmbeddedSharedInfo('products', $product)->save();

repo('orders')->refreshEmbeddedSharedInfo($orderId, 'customer', $customer);
```

Prefer this for targeted denorm refreshes. For fan-out across many parents,
keep using related-model queue mode (`mongez.queue.relatedModels`) /
`UpdateRelatedModelJob`.

## Fixed repository associate APIs

`MongoDBRepositoryManager::reassociate($parentNid, $related, $key)` and
`disassociate(...)` now keep the related model argument and apply it to the
loaded parent (previously the related argument was overwritten).

## Filter sugar (`FILTER_BY`)

| Operator | Effect |
|----------|--------|
| `embeddedNid` | `customer` → `customer.nid` (int) |
| `inEmbeddedNid` | same path with `whereIn` + int cast |
| `localizedLike` | `name` → `name.text` with LIKE |
| `localized` | exact match on `*.text` |

```php
const FILTER_BY = [
    'embeddedNid' => [
        'customer',
        'city' => 'shippingAddress.city',
    ],
    'inEmbeddedNid' => [
        'products' => 'items.product',
    ],
    'localizedLike' => [
        'name',
        'productName' => 'items.product.name',
    ],
];
```

Paths that already end with `.nid` / `.text` are left unchanged.

## Test assertions

```php
$response->assertRecordNid(42);
$response->assertRecordsHaveNid(); // data.records[*].nid
```
