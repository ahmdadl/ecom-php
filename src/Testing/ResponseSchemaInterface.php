<?php

declare(strict_types=1);

namespace HZ\Illuminate\Mongez\Testing;

interface ResponseSchemaInterface
{
    /**
     * A flag to determine if the unit key is missing from the response
     * 
     * @const string
     */
    const MISSING_RESPONSE_KEY = '__MISSING__KEY__';

    /**
     * Validate the response
     * 
     * @return self
     */
    public function validate();

    /**
     * Determine if the response schema must be strict
     *
     * @return  ResponseSchemaInterface
     */
    public function strict(bool $isStrict);

    /**
     * Determine if the response is valid
     */
    public function isValid(): bool;

    /**
     * Set the response value
     *
     * @param  mixed $value
     */
    public function setValue($value): ResponseSchemaInterface;

    /**
     * Get errors list
     *
     * @return array<int, array<string, mixed>>
     */
    public function errorsList(): array;
}
