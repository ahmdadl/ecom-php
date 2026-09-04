<?php

declare(strict_types=1);

namespace HZ\Illuminate\Mongez\Tests;

use HZ\Illuminate\Mongez\Database\Filters\MongoDBFilter;
use Illuminate\Database\Eloquent\Builder;
use Mockery;
use PHPUnit\Framework\TestCase as BaseTestCase;

final class MongoDBFilterSugarTest extends BaseTestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_filter_map_exposes_embedded_and_localized_operators(): void
    {
        $filter = new MongoDBFilter();
        $map = $filter->filterMap();

        $this->assertArrayHasKey('embeddedNid', $map);
        $this->assertArrayHasKey('inEmbeddedNid', $map);
        $this->assertArrayHasKey('localizedLike', $map);
        $this->assertSame('filterEmbeddedNid', $map['embeddedNid']);
        $this->assertSame('filterLocalizedLike', $map['localizedLike']);
    }

    public function test_embedded_nid_appends_nid_suffix_and_casts_int(): void
    {
        $query = Mockery::mock(Builder::class);
        $query->shouldReceive('where')->once()->with('customer.nid', 42)->andReturnSelf();
        $query->shouldReceive('where')->once()->with('shippingAddress.city.nid', 42)->andReturnSelf();

        $filter = new MongoDBFilter();
        $filter->setQuery($query);
        $filter->filterEmbeddedNid(['customer', 'shippingAddress.city.nid'], '42');

        $this->assertTrue(true);
    }

    public function test_in_embedded_nid_uses_where_in_with_ints(): void
    {
        $query = Mockery::mock(Builder::class);
        $query->shouldReceive('whereIn')->once()->with('customer.nid', [1, 2])->andReturnSelf();

        $filter = new MongoDBFilter();
        $filter->setQuery($query);
        $filter->filterInEmbeddedNid(['customer'], ['1', '2']);

        $this->assertTrue(true);
    }

    public function test_localized_like_appends_text_and_uses_like(): void
    {
        $inner = Mockery::mock(Builder::class);
        $inner->shouldReceive('whereLike')->once()->with('name.text', 'chair')->andReturnSelf();
        $inner->shouldReceive('orWhereLike')->once()->with('items.product.name.text', 'chair')->andReturnSelf();

        $query = Mockery::mock(Builder::class);
        $query->shouldReceive('where')->once()->andReturnUsing(function ($callback) use ($query, $inner) {
            $callback($inner);

            return $query;
        });

        $filter = new MongoDBFilter();
        $filter->setQuery($query);
        $filter->filterLocalizedLike(['name', 'items.product.name.text'], 'chair');

        $this->assertTrue(true);
    }

    public function test_in_bool_map_points_to_existing_method(): void
    {
        $filter = new MongoDBFilter();
        $map = $filter->filterMap();

        $this->assertSame('filterInBoolean', $map['inBool']);
        $this->assertSame('filterInBoolean', $map['inBoolean']);
        $this->assertTrue(method_exists($filter, $map['inBool']));
    }
}
