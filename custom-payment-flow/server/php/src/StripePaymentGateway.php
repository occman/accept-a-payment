<?php

namespace App;

/**
 * Thin adapter over the Stripe SDK; its methods perform live API calls and
 * are exercised only against the real service.
 *
 * @codeCoverageIgnore
 */
final class StripePaymentGateway implements PaymentGateway
{
    public function __construct(private readonly \Stripe\StripeClient $client)
    {
    }

    public function createPaymentIntent(array $params): \Stripe\PaymentIntent
    {
        return $this->client->paymentIntents->create($params);
    }

    public function retrievePaymentIntent(string $id): \Stripe\PaymentIntent
    {
        return $this->client->paymentIntents->retrieve($id);
    }
}
