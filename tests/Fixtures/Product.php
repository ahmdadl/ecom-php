<?php

declare(strict_types=1);

namespace HZ\Illuminate\Mongez\Tests\Fixtures;

use HZ\Illuminate\Mongez\Database\Eloquent\HasPublishedScope;
use HZ\Illuminate\Mongez\Database\Eloquent\MongoDB\Model;

/**
 * @property int $nid
 * @property string $id
 * @property string $name
 * @property int $price
 */
class Product extends Model
{
    use HasPublishedScope;

    /**
     * Collection name
     *
     * @var string
     */
    protected $table = 'products';
}
