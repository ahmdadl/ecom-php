<?php

namespace HZ\Illuminate\Mongez\Tests\Fixtures;

use HZ\Illuminate\Mongez\Resources\JsonResourceManager;

class ProductResource extends JsonResourceManager
{
    /**
     * {@inheritDoc}
     */
    const DATA = ['nid', 'name'];

    /**
     * {@inheritDoc}
     */
    const INTEGER_DATA = ['price'];
}
