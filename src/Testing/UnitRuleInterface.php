<?php

declare(strict_types=1);

namespace HZ\Illuminate\Mongez\Testing;

use HZ\Illuminate\Mongez\Testing\Units\UnitType;

interface UnitRuleInterface
{
    /**
     * Determine whether the rule will be executed.
     */
    public function executable(bool $executable): UnitRuleInterface;

    /**
     * Get rule name, it will be used to be dynamically called from the unit type.
     */
    public function name(): string;

    /**
     * Get rule message attributes
     *
     * @return array<string, mixed>
     */
    public function getMessageAttributes(): array;

    /**
     * Rule options that can be passe
     *
     * @param  array<int, mixed> $options
     */
    public function setOptions(array $options): UnitRuleInterface;

    /**
     * Called before calling the rule validation so the rule can check its own requirements first
     *
     * @return void
     */
    public function beforeValidating();

    /**
     * Determine if the rule is valid
     */
    public function isValid(): bool;

    /**
     * Set the unit key
     */
    public function setKey(string $key): UnitRuleInterface;

    /**
     * Set the unit parent key
     */
    public function setParentKey(string $parentKey): UnitRuleInterface;

    /**
     * Set the unit key namespace
     */
    public function setKeyNamespace(string $keyNamespace): UnitRuleInterface;

    /**
     * Set the unit that holds the rule
     */
    public function setUnit(UnitType $unit): UnitRuleInterface;

    /**
     * Set the unit value
     *
     * @param  mixed $value
     */
    public function setValue($value): UnitRuleInterface;

    /**
     * Get error message
     */
    public function getErrorMessage(): string;
}
