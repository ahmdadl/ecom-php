<?php

declare(strict_types=1);

namespace HZ\Illuminate\Mongez\Testing\Units;

class ErrorKeyValueUnit extends ObjectUnit
{
    /**
     * Error key name
     */
    protected string $errorKeyName = '';

    /**
     * {@inheritDoc}
     */
    public function beforeValidation()
    {
        $units = [
            'key' => 'string',
            'value' => 'string',
        ];

        if ($this->errorKeyName) {
            $units['key'] = ['string', 'equal:' . $this->errorKeyName];
        }

        $this->setUnits($units);
    }

    /**
     * Set the error key name
     */
    public function setErrorKeyName(string $errorKeyName): self
    {
        $this->errorKeyName = $errorKeyName;
        return $this;
    }
}
