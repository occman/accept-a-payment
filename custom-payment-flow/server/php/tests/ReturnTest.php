<?php

namespace Tests;

use Stripe\Exception\InvalidRequestException;
use Stripe\PaymentIntent;

final class ReturnTest extends ScriptTestCase
{
    public function testRetrievesPaymentIntentFromQueryStringAndRendersStatus(): void
    {
        $_GET['payment_intent'] = 'pi_123';
        $this->paymentIntents->expects($this->once())
            ->method('retrieve')
            ->with('pi_123')
            ->willReturn(PaymentIntent::constructFrom([
                'id' => 'pi_123',
                'status' => 'succeeded',
                'amount' => 1999,
                'currency' => 'eur',
                'payment_method' => 'pm_456',
            ]));

        $result = $this->runScript('return.php');

        self::assertSame(200, $result->status);
        self::assertStringContainsString('https://dashboard.stripe.com/test/payments/pi_123', $result->body);
        self::assertStringContainsString('<p>ID pi_123</p>', $result->body);
        self::assertStringContainsString('<p>Status: succeeded</p>', $result->body);
        self::assertStringContainsString('<p>Amount: 1999</p>', $result->body);
        self::assertStringContainsString('<p>Currency: eur</p>', $result->body);
        self::assertStringContainsString('<p>Payment Method: pm_456</p>', $result->body);
    }

    public function testStripeErrorsAreNotCaught(): void
    {
        $_GET['payment_intent'] = 'pi_missing';
        $this->paymentIntents->method('retrieve')->willThrowException(
            InvalidRequestException::factory('No such payment_intent: pi_missing', 404)
        );

        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessage('No such payment_intent: pi_missing');

        $this->runScript('return.php');
    }
}
