<?php

declare(strict_types=1);

namespace HZ\Illuminate\Mongez\Testing\Rules;

use HZ\Illuminate\Mongez\Testing\MissingUnitRuleOptionsException;
use HZ\Illuminate\Mongez\Testing\UnitRuleInterface;

class MaxLengthRule extends UnitRule implements UnitRuleInterface
{
    /**
     * {@inheritDoc}
     */
    const NAME = 'maxLength';

    /**
     * {@inheritDoc}
     */
    public function beforeValidating()
    {
        if (!isset($this->options[0])) {
            throw new MissingUnitRuleOptionsException('maxLength rule needs a value to compare the given value length.');
        }
    }

    /**
     * Determine if the rule is valid
     */
    public function isValid(): bool
    {
        return count($this->value) <= $this->options[0];
    }

    /**
     * Get error message
     */
    public function getErrorMessage(): string
    {
        return ':key\'s length is :lengthValue , expected to be :length or less.';
    }

    /**
     * Get message attributes
     *
     * @return array<string, mixed>
     */
    public function getMessageAttributes(): array
    {
        return [
            'lengthValue' => count($this->value),
            'length' => $this->options[0],
        ];
    }
}
