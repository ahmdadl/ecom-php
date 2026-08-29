<?php

declare(strict_types=1);

namespace HZ\Illuminate\Mongez\Testing;

use HZ\Illuminate\Mongez\Testing\Traits\WithCreatingRequests;

abstract class BaseCreateAdminTest extends BaseCrudAdminTest
{
    use WithCreatingRequests;
}
