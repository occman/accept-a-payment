<?php

namespace App;

final class WebhookHandler
{
    /**
     * Verifies the Stripe signature header and builds the event. The
     * signature check is local HMAC work, so no network access is needed.
     *
     * @throws \Stripe\Exception\SignatureVerificationException when the
     *         payload or signature is invalid
     * @throws \UnexpectedValueException when the payload is not valid JSON
     */
    public static function constructEvent(string $payload, string $sigHeader, string $secret): \Stripe\Event
    {
        return \Stripe\Webhook::constructEvent($payload, $sigHeader, $secret);
    }

    /**
     * @return array<string, string>
     */
    public static function handle(\Stripe\Event $event): array
    {
        if ('payment_intent.succeeded' === $event->type) {
            // Fulfill any orders, e-mail receipts, etc. To cancel the payment
            // you will need to issue a Refund (https://stripe.com/docs/api/refunds).
            error_log('💰 Payment received!');
        } elseif ('payment_intent.payment_failed' === $event->type) {
            error_log('❌ Payment failed.');
        }

        return ['status' => 'success'];
    }
}
