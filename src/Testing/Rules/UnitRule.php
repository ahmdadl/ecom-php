<?php

declare(strict_types=1);

namespace HZ\Illuminate\Mongez\Testing\Rules;

use HZ\Illuminate\Mongez\Testing\Traits\Messageable;
use HZ\Illuminate\Mongez\Testing\Traits\WithKeyAndValue;
use HZ\Illuminate\Mongez\Testing\UnitRuleInterface;
use HZ\Illuminate\Mongez\Testing\Units\UnitType;

abstract class UnitRule implements UnitRuleInterface
{
    use WithKeyAndValue;
    use Messageable;

    /**
     * Rule name
     * 
     * @const string
     */
    const NAME = '';

    /**
     * The unit that holds the rule
     * 
     * @var UnitType
     */
    protected $unit;


    /**
     * Rule Error Message
     */
    protected string $errorMessage = '';

    /**
     * Rule options
     */
    protected array $options = [];

    /**
     * Set rule unit
     *
     * @var  UnitType $unit
     */
    public function setUnit(UnitType $unit): UnitRule
    {
        $this->unit = $unit;
        return $this;
    }

    /**
     * Determine whether the rule will be executed.
     *
     * @return UnitRule
     */
    public function executable(bool $executable): UnitRuleInterface
    {
        return $this;
    }

    /**
     * Called before calling the rule validation so the rule can check its own requirements first
     * 
     * @return void
     */
    public function beforeValidating()
    {
    }

    /**
     * Rule options that can be passe
     */
    public function setOptions(array $options): UnitRuleInterface
    {
        $this->options = $options;

        return $this;
    }

    /**
     * Determine if the rule is valid
     *
     * @param  mixed $value
     */
    public function isValid(): bool
    {
        return true;
    }

    /**
     * Get error message
     */
    public function getErrorMessage(): string
    {
        return $this->errorMessage;
    }

    /**
     * {@inheritDoc}
     */
    public function name(): string
    {
        return static::NAME;
    }

    /**
     * Get rule message attributes
     */
    public function getMessageAttributes(): array
    {
        return [];
    }
}
