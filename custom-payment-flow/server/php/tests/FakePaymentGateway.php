<?php

namespace App\Tests;

use App\PaymentGateway;

/**
 * In-memory PaymentGateway double: records the parameters it is called with
 * and returns (or throws) canned responses, so tests never touch the network.
 */
final class FakePaymentGateway implements PaymentGateway
{
    /** @var list<array<string, mixed>> */
    public array $createdIntents = [];

    /** @var list<string> */
    public array $retrievedIntentIds = [];

    /** @var array<string, mixed> */
    public array $nextIntent = [];

    public ?\Throwable $nextError = null;

    public function createPaymentIntent(array $params): \Stripe\PaymentIntent
    {
        $this->createdIntents[] = $params;

        if (null !== $this->nextError) {
            throw $this->nextError;
        }

        return \Stripe\PaymentIntent::constructFrom($this->nextIntent + [
            'id' => 'pi_123',
            'client_secret' => 'pi_123_secret_456',
            'status' => 'requires_payment_method',
        ]);
    }

    public function retrievePaymentIntent(string $id): \Stripe\PaymentIntent
    {
        $this->retrievedIntentIds[] = $id;

        if (null !== $this->nextError) {
            throw $this->nextError;
        }

        return \Stripe\PaymentIntent::constructFrom($this->nextIntent + [
            'id' => $id,
            'client_secret' => $id . '_secret_456',
            'status' => 'succeeded',
        ]);
    }
}
