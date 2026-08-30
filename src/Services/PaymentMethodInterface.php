<?php

declare(strict_types=1);

namespace HZ\Illuminate\Mongez\Contracts\Services\Payments;

interface PaymentMethodInterface
{
    /**
     * Initiate Payment
     * Payment methods may be passed as a payment gateway may provide multiple payment methods in one gate 
     */
    public function initiate(int $orderId, float $amount, string $paymentMethod): mixed;

    /**
     * Confirm Payment
     */
    public function confirm(int $orderId, string $checkoutId, string $paymentMethod): mixed;
}
