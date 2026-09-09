<?php

namespace App;

/**
 * Narrow seam around the Stripe SDK so handlers can be exercised offline
 * with a fake implementation.
 */
interface PaymentGateway
{
    /**
     * @param array<string, mixed> $params
     */
    public function createPaymentIntent(array $params): \Stripe\PaymentIntent;

    public function retrievePaymentIntent(string $id): \Stripe\PaymentIntent;
}
