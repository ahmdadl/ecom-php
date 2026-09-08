<?php

declare(strict_types=1);

namespace HZ\Illuminate\Mongez\Tests\Fixtures;

use HZ\Illuminate\Mongez\Resources\JsonResourceManager;

class LegacyIdProductResource extends JsonResourceManager
{
    const DATA = ['name'];

    const INTEGER_DATA = ['id', 'price'];
}
