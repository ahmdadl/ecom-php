<?php

declare(strict_types=1);

namespace HZ\Illuminate\Mongez\Tests\Fixtures;

use HZ\Illuminate\Mongez\Database\Eloquent\MongoDB\Model;

/**
 * @property int $nid
 * @property string $name
 */
class SharedInfoProduct extends Model
{
    protected $table = 'shared_info_products';

    public const SHARED_INFO = ['id', 'name'];
}
