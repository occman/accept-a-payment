<?php

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Stripe\Exception\InvalidRequestException;
use Stripe\PaymentIntent;

final class CreatePaymentIntentTest extends ScriptTestCase
{
    /**
     * Every payment-method page and the exact PaymentIntent params it sends.
     *
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function paymentMethodScripts(): iterable
    {
        $simple = static fn (string $type, string $currency, int $amount = 1999): array => [
            'payment_method_types' => [$type],
            'amount' => $amount,
            'currency' => $currency,
        ];

        yield 'card.php' => ['card.php', $simple('card', 'usd')];
        yield 'apple-pay.php' => ['apple-pay.php', $simple('card', 'usd')];
        yield 'google-pay.php' => ['google-pay.php', $simple('card', 'usd')];
        yield 'link.php' => ['link.php', [
            'payment_method_types' => ['link', 'card'],
            'amount' => 1999,
            'currency' => 'usd',
        ]];
        yield 'acss.php' => ['acss.php', [
            'payment_method_types' => ['acss_debit'],
            'amount' => 1999,
            'currency' => 'usd',
            'payment_method_options' => [
                'acss_debit' => [
                    'mandate_options' => [
                        'payment_schedule' => 'sporadic',
                        'transaction_type' => 'personal',
                    ],
                ],
            ],
        ]];
        yield 'afterpay-clearpay.php' => ['afterpay-clearpay.php', $simple('afterpay_clearpay', 'usd')];
        yield 'alipay.php' => ['alipay.php', $simple('alipay', 'usd')];
        yield 'bancontact.php' => ['bancontact.php', $simple('bancontact', 'eur')];
        yield 'becs-debit.php' => ['becs-debit.php', $simple('au_becs_debit', 'aud')];
        yield 'boleto.php' => ['boleto.php', $simple('boleto', 'brl', 5000)];
        yield 'eps.php' => ['eps.php', $simple('eps', 'eur')];
        yield 'fpx.php' => ['fpx.php', $simple('fpx', 'myr')];
        yield 'giropay.php' => ['giropay.php', $simple('giropay', 'eur')];
        yield 'grabpay.php' => ['grabpay.php', $simple('grabpay', 'myr')];
        yield 'ideal.php' => ['ideal.php', $simple('ideal', 'eur')];
        yield 'konbini.php' => ['konbini.php', [
            'payment_method_types' => ['konbini'],
            'amount' => 1999,
            'currency' => 'jpy',
            'payment_method_options' => [
                'konbini' => [
                    'product_description' => 'Tシャツ',
                    'expires_after_days' => 3,
                ],
            ],
        ]];
        yield 'oxxo.php' => ['oxxo.php', $simple('oxxo', 'mxn')];
        yield 'p24.php' => ['p24.php', $simple('p24', 'eur')];
        yield 'sepa-debit.php' => ['sepa-debit.php', $simple('sepa_debit', 'eur')];
        yield 'sofort.php' => ['sofort.php', $simple('sofort', 'eur')];
    }

    /**
     * @param array<string, mixed> $expectedParams
     */
    #[DataProvider('paymentMethodScripts')]
    public function testCreatesPaymentIntentAndRendersClientSecret(string $script, array $expectedParams): void
    {
        $this->paymentIntents->expects($this->once())
            ->method('create')
            ->with($expectedParams)
            ->willReturn(PaymentIntent::constructFrom([
                'id' => 'pi_123',
                'client_secret' => 'pi_123_secret_456',
            ]));

        $result = $this->runScript($script);

        self::assertSame(200, $result->status);
        self::assertStringContainsString('<!DOCTYPE html>', $result->body);
        self::assertStringContainsString("Stripe('pk_test_123'", $result->body);
        self::assertStringContainsString("'pi_123_secret_456'", $result->body);
        self::assertStringNotContainsString('sk_test_123', $result->body);
        self::assertSame('', $this->errorLog());
    }

    #[DataProvider('paymentMethodScripts')]
    public function testStripeApiErrorRendersErrorPageWith400(string $script): void
    {
        $this->paymentIntents->method('create')->willThrowException(
            InvalidRequestException::factory(
                'Amount must be at least 50 cents.',
                400,
                null,
                ['error' => ['message' => 'Amount must be at least 50 cents.']]
            )
        );

        $result = $this->runScript($script);

        self::assertSame(400, $result->status);
        self::assertStringContainsString('<h1>Error</h1>', $result->body);
        self::assertStringContainsString('Failed to create a PaymentIntent', $result->body);
        self::assertStringNotContainsString('<!DOCTYPE html>', $result->body);
        self::assertStringNotContainsString('Amount must be at least', $result->body);
        self::assertStringContainsString('Amount must be at least 50 cents.', $this->errorLog());
    }

    #[DataProvider('paymentMethodScripts')]
    public function testUnexpectedErrorReturns500WithEmptyBody(string $script): void
    {
        $this->paymentIntents->method('create')->willThrowException(
            new \RuntimeException('connection reset')
        );

        $result = $this->runScript($script);

        self::assertSame(500, $result->status);
        self::assertSame('', $result->body);
        self::assertStringContainsString('connection reset', $this->errorLog());
    }
}
