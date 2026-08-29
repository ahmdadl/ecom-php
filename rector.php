<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\MethodCall\RemoveNullArgOnNullDefaultParamRector;
use Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withSkip([
        __DIR__ . '/src/Testing/BaseAdminDeleteTest.php',
        __DIR__ . '/src/Testing/BaseCreateAdminTest.php',
        __DIR__ . '/src/Testing/BaseUpdateAdminTest.php',
        __DIR__ . '/src/Testing/Traits/WithCreatingRequests.php',
        __DIR__ . '/src/Helpers/functions.php',
        RemoveNullArgOnNullDefaultParamRector::class,
        ClassPropertyAssignToConstructorPromotionRector::class,
    ])
    ->withPHPStanConfigs([__DIR__ . '/phpstan.neon'])
    ->withPhpSets()
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        earlyReturn: true,
    );
