<?php

namespace HZ\Illuminate\Mongez\Tests\Fixtures;

use HZ\Illuminate\Mongez\Repository\MongoDBRepositoryManager;

class ProductsRepository extends MongoDBRepositoryManager
{
    /**
     * {@inheritDoc}
     */
    const NAME = 'test-products';

    /**
     * {@inheritDoc}
     */
    const MODEL = Product::class;

    /**
     * {@inheritDoc}
     */
    const TABLE = 'products';

    /**
     * {@inheritDoc}
     */
    const DATA = ['name'];

    /**
     * {@inheritDoc}
     */
    const INTEGER_DATA = ['price'];

    /**
     * {@inheritDoc}
     */
    const RESOURCE = ProductResource::class;
}
