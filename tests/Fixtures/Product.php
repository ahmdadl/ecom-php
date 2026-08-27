<?php

namespace HZ\Illuminate\Mongez\Tests\Fixtures;

use HZ\Illuminate\Mongez\Database\Eloquent\HasPublishedScope;
use HZ\Illuminate\Mongez\Database\Eloquent\MongoDB\Model;

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

