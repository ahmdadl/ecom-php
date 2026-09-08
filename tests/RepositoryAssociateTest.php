<?php

declare(strict_types=1);

namespace HZ\Illuminate\Mongez\Tests;

use HZ\Illuminate\Mongez\Events\Events;
use HZ\Illuminate\Mongez\Tests\Fixtures\Product;
use HZ\Illuminate\Mongez\Tests\Fixtures\ProductsRepository;
use Illuminate\Http\Request;

final class RepositoryAssociateTest extends TestCase
{
    public function test_reassociate_applies_related_shared_info_to_parent_embed(): void
    {
        $parent = new class extends Product {
            public bool $saved = false;

            public function save(array $options = []): bool
            {
                $this->saved = true;

                return true;
            }
        };
        $parent->setAttribute('products', [
            ['nid' => 5, 'name' => 'Old'],
        ]);

        $related = new Product();
        $related->forceFill(['nid' => 5, 'name' => 'Fresh']);

        $repo = $this->repositoryWithParent($parent);
        $repo->reassociate(1, $related, 'products');

        $this->assertTrue($parent->saved);
        $this->assertSame('Fresh', $parent->products[0]['name']);
        $this->assertSame(5, $parent->products[0]['nid']);
    }

    public function test_disassociate_removes_related_embed_from_parent(): void
    {
        $parent = new class extends Product {
            public bool $saved = false;

            public function save(array $options = []): bool
            {
                $this->saved = true;

                return true;
            }
        };
        $parent->setAttribute('products', [
            ['nid' => 5, 'name' => 'RemoveMe'],
            ['nid' => 6, 'name' => 'Keep'],
        ]);

        $related = new Product();
        $related->forceFill(['nid' => 5, 'name' => 'RemoveMe']);

        $repo = $this->repositoryWithParent($parent);
        $repo->disassociate(1, $related, 'products');

        $this->assertTrue($parent->saved);
        $this->assertCount(1, $parent->products);
        $this->assertSame('Keep', $parent->products[0]['name']);
    }

    public function test_patch_embedded_merges_and_saves_parent(): void
    {
        $parent = new class extends Product {
            public bool $saved = false;

            public function save(array $options = []): bool
            {
                $this->saved = true;

                return true;
            }
        };
        $parent->setAttribute('items', [
            ['nid' => 10, 'qty' => 1],
            ['nid' => 20, 'qty' => 2],
        ]);

        $repo = $this->repositoryWithParent($parent);
        $result = $repo->patchEmbedded(1, 'items', 20, ['qty' => 8]);

        $this->assertSame($parent, $result);
        $this->assertTrue($parent->saved);
        $this->assertSame(1, $parent->items[0]['qty']);
        $this->assertSame(8, $parent->items[1]['qty']);
    }

    private function repositoryWithParent(Product $parent): ProductsRepository
    {
        return new class($parent) extends ProductsRepository {
            public function __construct(private Product $parentModel)
            {
                parent::__construct(new Request(), new Events());
            }

            public function getModel($id)
            {
                return $this->parentModel;
            }
        };
    }
}
