<?php

namespace App\Tests;

use App\PaymentMethods;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PaymentMethodsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, list<string>, int, string}>
     */
    public static function pages(): iterable
    {
        yield 'acss' => ['acss', ['acss_debit'], 1999, 'usd'];
        yield 'afterpay-clearpay' => ['afterpay-clearpay', ['afterpay_clearpay'], 1999, 'usd'];
        yield 'alipay' => ['alipay', ['alipay'], 1999, 'usd'];
        yield 'apple-pay' => ['apple-pay', ['card'], 1999, 'usd'];
        yield 'bancontact' => ['bancontact', ['bancontact'], 1999, 'eur'];
        yield 'becs-debit' => ['becs-debit', ['au_becs_debit'], 1999, 'aud'];
        yield 'boleto' => ['boleto', ['boleto'], 5000, 'brl'];
        yield 'card' => ['card', ['card'], 1999, 'usd'];
        yield 'eps' => ['eps', ['eps'], 1999, 'eur'];
        yield 'fpx' => ['fpx', ['fpx'], 1999, 'myr'];
        yield 'giropay' => ['giropay', ['giropay'], 1999, 'eur'];
        yield 'google-pay' => ['google-pay', ['card'], 1999, 'usd'];
        yield 'grabpay' => ['grabpay', ['grabpay'], 1999, 'myr'];
        yield 'ideal' => ['ideal', ['ideal'], 1999, 'eur'];
        yield 'konbini' => ['konbini', ['konbini'], 1999, 'jpy'];
        yield 'link' => ['link', ['link', 'card'], 1999, 'usd'];
        yield 'oxxo' => ['oxxo', ['oxxo'], 1999, 'mxn'];
        yield 'p24' => ['p24', ['p24'], 1999, 'eur'];
        yield 'sepa-debit' => ['sepa-debit', ['sepa_debit'], 1999, 'eur'];
        yield 'sofort' => ['sofort', ['sofort'], 1999, 'eur'];
    }

    /**
     * @param list<string> $methodTypes
     */
    #[DataProvider('pages')]
    public function testParamsForPage(string $page, array $methodTypes, int $amount, string $currency): void
    {
        $params = PaymentMethods::params($page);

        self::assertSame($methodTypes, $params['payment_method_types']);
        self::assertSame($amount, $params['amount']);
        self::assertSame($currency, $params['currency']);
    }

    public function testKonbiniIncludesKonbiniOptions(): void
    {
        $params = PaymentMethods::params('konbini');

        self::assertSame('Tシャツ', $params['payment_method_options']['konbini']['product_description']);
        self::assertSame(3, $params['payment_method_options']['konbini']['expires_after_days']);
    }

    public function testAcssIncludesMandateOptions(): void
    {
        $params = PaymentMethods::params('acss');

        self::assertSame(
            ['payment_schedule' => 'sporadic', 'transaction_type' => 'personal'],
            $params['payment_method_options']['acss_debit']['mandate_options']
        );
    }

    public function testUnknownPageThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PaymentMethods::params('not-a-page');
    }
}
