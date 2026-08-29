<?php

declare(strict_types=1);

namespace HZ\Illuminate\Mongez\Testing;

use HZ\Illuminate\Mongez\Testing\Units\ObjectUnit;

class ResponseSchema extends ObjectUnit implements ResponseSchemaInterface
{
    /**
     * Constructor
     */
    public function __construct(array $unitsList = [], array $options = [])
    {
        $options = array_merge([
            'rootKey' => config('mongez.testing.response.rootKey'),
        ], $options);

        $rootKey = $options['rootKey'];

        if ($rootKey) {
            $unitsList = [
                $rootKey => new ObjectUnit($unitsList),
            ];
        }

        parent::__construct($unitsList);
    }
}
