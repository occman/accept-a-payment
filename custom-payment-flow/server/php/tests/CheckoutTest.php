<?php

namespace App\Tests;

use App\Checkout;
use App\PageAborted;
use App\PaymentMethods;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CheckoutTest extends TestCase
{
    private FakePaymentGateway $gateway;

    protected function setUp(): void
    {
        $this->gateway = new FakePaymentGateway();
        http_response_code(200);
    }

    public function testCreateIntentReturnsIntentFromGateway(): void
    {
        $intent = Checkout::createIntent($this->gateway, PaymentMethods::params('card'));

        self::assertSame('pi_123', $intent->id);
        self::assertSame('pi_123_secret_456', $intent->client_secret);
        self::assertSame(
            [['payment_method_types' => ['card'], 'amount' => 1999, 'currency' => 'usd']],
            $this->gateway->createdIntents
        );
    }

    /**
     * Every payment page's parameters are forwarded to the gateway verbatim.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function paymentMethodTypes(): iterable
    {
        yield 'card' => ['card', 'card'];
        yield 'link (with card)' => ['link', 'link'];
        yield 'bank redirect' => ['ideal', 'ideal'];
        yield 'voucher with options' => ['konbini', 'konbini'];
        yield 'bank debit with mandate options' => ['acss', 'acss_debit'];
        yield 'wallet rendered as card' => ['apple-pay', 'card'];
    }

    #[DataProvider('paymentMethodTypes')]
    public function testCreateIntentForwardsPaymentMethodTypes(string $page, string $methodType): void
    {
        $params = PaymentMethods::params($page);

        $intent = Checkout::createIntent($this->gateway, $params);

        self::assertSame('pi_123_secret_456', $intent->client_secret);
        self::assertContains($methodType, $this->gateway->createdIntents[0]['payment_method_types']);
        self::assertSame($params, $this->gateway->createdIntents[0]);
    }

    public function testApiErrorEmits400AndErrorFragment(): void
    {
        $this->gateway->nextError = \Stripe\Exception\InvalidRequestException::factory(
            'Your card was declined.',
            400,
            null,
            ['error' => ['message' => 'Your card was declined.']]
        );

        $this->expectException(PageAborted::class);
        $this->expectOutputRegex('/Failed to create a PaymentIntent/');

        try {
            Checkout::createIntent($this->gateway, PaymentMethods::params('card'));
        } finally {
            self::assertSame(400, http_response_code());
        }
    }

    public function testUnexpectedErrorEmits500(): void
    {
        $this->gateway->nextError = new \RuntimeException('boom');

        $this->expectException(PageAborted::class);

        try {
            Checkout::createIntent($this->gateway, PaymentMethods::params('card'));
        } finally {
            self::assertSame(500, http_response_code());
        }
    }

    public function testRetrieveIntentReturnsGatewayResult(): void
    {
        $intent = $this->gateway->retrievePaymentIntent('pi_999');

        self::assertSame('pi_999', $intent->id);
        self::assertSame('succeeded', $intent->status);
        self::assertSame(['pi_999'], $this->gateway->retrievedIntentIds);
    }
}
