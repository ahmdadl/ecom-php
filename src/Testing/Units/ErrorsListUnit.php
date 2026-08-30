<?php

declare(strict_types=1);

namespace HZ\Illuminate\Mongez\Testing\Units;

class ErrorsListUnit extends ArrayOfUnit
{
    /**
     * {@inheritDoc}
     *
     * @param  array<int, string> $errorsKeys
     */
    public function __construct(array $errorsKeys)
    {
        parent::__construct(ErrorKeyValueUnit::class);

        $this->length(count($errorsKeys));

        $this->each(function ($unit, $index) use ($errorsKeys) {
            $unit->setErrorKeyName($errorsKeys[$index]);
        });
    }
}
