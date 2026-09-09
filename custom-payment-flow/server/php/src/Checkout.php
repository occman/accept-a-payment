<?php

namespace App;

final class Checkout
{
    /**
     * Creates a PaymentIntent through the gateway, mapping Stripe errors to
     * the HTTP responses the checkout pages display.
     *
     * @param array<string, mixed> $params
     *
     * @throws PageAborted once the error response has been emitted; the page
     *         should catch it and stop rendering.
     */
    public static function createIntent(PaymentGateway $gateway, array $params): \Stripe\PaymentIntent
    {
        try {
            return $gateway->createPaymentIntent($params);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            http_response_code(400);
            error_log($e->getError()->message);
            echo "  <h1>Error</h1>\n  <p>Failed to create a PaymentIntent</p>\n  <p>Please check the server logs for more information</p>\n";

            throw new PageAborted();
        } catch (\Exception $e) {
            error_log((string) $e);
            http_response_code(500);

            throw new PageAborted();
        }
    }
}
