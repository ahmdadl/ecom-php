<?php

declare(strict_types=1);

namespace HZ\Illuminate\Mongez\Contracts\Services\Payments;

interface PaymentGatewayResponse
{
    /**
     * Payment transaction is pending
     * 
     * @const string
     */
    const PENDING = 'pending';
    
    /**
     * Payment transaction is completed
     * 
     * @const string
     */
    const COMPLETED = 'completed';

    /**
     * Payment transaction is failed
     * 
     * @const string
     */
    const FAILED = 'failed';

    /**
     * Get payment status success | failed or pending
     */
    public function getStatus(): string;

    /**
     * Get payment status code returned from gateway
     */
    public function getStatusCode(): string;

    /**
     * Determine if payment transaction is pending
     */
    public function isPending(): bool;
    
    /**
     * Determine if payment transaction has failed
     */
    public function hasFailed(): bool;

    /**
     * Determine if payment transaction has been completed
     */
    public function isCompleted(): bool;

    /**
     * Get payment gateway error message
     */
    public function getErrorMessage(): string;

    /**
     * Get payment gateway message wether completed, failed, or pending
     */
    public function getMessage(): string;

    /**
     * Get a generic value from payment response
     *
     * @return mixed
     */
    public function get(string $key);
}
