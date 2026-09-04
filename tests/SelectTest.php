<?php

declare(strict_types=1);

namespace HZ\Illuminate\Mongez\Tests;

use HZ\Illuminate\Mongez\Repository\Select;
use PHPUnit\Framework\TestCase as BaseTestCase;

final class SelectTest extends BaseTestCase
{
    public function test_remove_drops_matching_column(): void
    {
        $select = new Select(['nid', 'name', 'price']);

        $select->remove('name');

        $this->assertSame(['nid', 'price'], $select->list());
        $this->assertFalse($select->has('name'));
    }

    public function test_replace_swaps_column_for_new_ones(): void
    {
        $select = new Select(['nid', 'name']);

        $select->replace('name', 'title', 'slug');

        $this->assertSame(['nid', 'title', 'slug'], $select->list());
    }

    public function test_replace_is_noop_when_column_missing(): void
    {
        $select = new Select(['nid']);

        $select->replace('missing', 'other');

        $this->assertSame(['nid'], $select->list());
    }
}
