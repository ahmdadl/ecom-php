<?php

declare(strict_types=1);

namespace HZ\Illuminate\Mongez\Tests;

use HZ\Illuminate\Mongez\Tests\Fixtures\Product;

final class EmbeddedDocumentTest extends TestCase
{
    public function test_patch_embedded_merges_into_matching_array_element(): void
    {
        $order = new Product();
        $order->setAttribute('items', [
            ['nid' => 1, 'sku' => 'A', 'qty' => 1],
            ['nid' => 2, 'sku' => 'B', 'qty' => 2],
        ]);

        $order->patchEmbedded('items', 2, ['qty' => 5, 'note' => 'updated']);

        $this->assertSame([
            ['nid' => 1, 'sku' => 'A', 'qty' => 1],
            ['nid' => 2, 'sku' => 'B', 'qty' => 5, 'note' => 'updated'],
        ], $order->items);
    }

    public function test_patch_embedded_matches_by_criteria_array(): void
    {
        $order = new Product();
        $order->setAttribute('items', [
            ['nid' => 1, 'sku' => 'A', 'qty' => 1],
            ['nid' => 2, 'sku' => 'B', 'qty' => 2],
        ]);

        $order->patchEmbedded('items', ['sku' => 'A'], ['qty' => 9]);

        $this->assertSame(9, $order->items[0]['qty']);
        $this->assertSame(2, $order->items[1]['qty']);
    }

    public function test_patch_embedded_leaves_siblings_untouched_when_no_match(): void
    {
        $order = new Product();
        $order->setAttribute('items', [
            ['nid' => 1, 'sku' => 'A', 'qty' => 1],
        ]);

        $order->patchEmbedded('items', 99, ['qty' => 3]);

        $this->assertSame([['nid' => 1, 'sku' => 'A', 'qty' => 1]], $order->items);
    }

    public function test_patch_embedded_can_append_when_missing(): void
    {
        $order = new Product();
        $order->setAttribute('items', [
            ['nid' => 1, 'sku' => 'A', 'qty' => 1],
        ]);

        $order->patchEmbedded('items', 3, ['nid' => 3, 'sku' => 'C', 'qty' => 1], createIfMissing: true);

        $this->assertCount(2, $order->items);
        $this->assertSame(3, $order->items[1]['nid']);
    }

    public function test_refresh_embedded_shared_info_replaces_singular_embed(): void
    {
        $order = new Product();
        $order->setAttribute('customer', ['nid' => 10, 'name' => 'Old']);

        $customer = new Product();
        $customer->forceFill(['nid' => 10, 'name' => 'New']);

        $order->refreshEmbeddedSharedInfo('customer', $customer);

        $this->assertSame(10, $order->customer['nid']);
        $this->assertSame('New', $order->customer['name']);
    }

    public function test_refresh_embedded_shared_info_updates_matching_list_element(): void
    {
        $order = new Product();
        $order->setAttribute('products', [
            ['nid' => 1, 'name' => 'Old A'],
            ['nid' => 2, 'name' => 'Old B'],
        ]);

        $product = new Product();
        $product->forceFill(['nid' => 2, 'name' => 'Fresh B']);

        $order->refreshEmbeddedSharedInfo('products', $product);

        $this->assertSame('Old A', $order->products[0]['name']);
        $this->assertSame('Fresh B', $order->products[1]['name']);
        $this->assertSame(2, $order->products[1]['nid']);
    }

    public function test_reassociate_replaces_matching_document_by_nid(): void
    {
        $order = new Product();
        $order->setAttribute('products', [
            ['nid' => 1, 'name' => 'A'],
            ['nid' => 2, 'name' => 'B'],
        ]);

        $product = new Product();
        $product->forceFill(['nid' => 2, 'name' => 'B2']);

        $order->reassociate($product, 'products');

        $this->assertSame('A', $order->products[0]['name']);
        $this->assertSame('B2', $order->products[1]['name']);
    }

    public function test_disassociate_removes_matching_document_by_nid(): void
    {
        $order = new Product();
        $order->setAttribute('products', [
            ['nid' => 1, 'name' => 'A'],
            ['nid' => 2, 'name' => 'B'],
        ]);

        $product = new Product();
        $product->forceFill(['nid' => 1, 'name' => 'A']);

        $order->disassociate($product, 'products');

        $this->assertCount(1, $order->products);
        $this->assertSame(2, $order->products[0]['nid']);
    }
}
