<?php

namespace App;

/**
 * PaymentIntent parameters used by each payment-method page, keyed by the
 * page name (the PHP file basename without the .php extension).
 */
final class PaymentMethods
{
    /** @var array<string, array<string, mixed>> */
    private const PARAMS = [
        'acss' => [
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
        ],
        'afterpay-clearpay' => [
            'payment_method_types' => ['afterpay_clearpay'],
            'amount' => 1999,
            'currency' => 'usd',
        ],
        'alipay' => [
            'payment_method_types' => ['alipay'],
            'amount' => 1999,
            'currency' => 'usd',
        ],
        'apple-pay' => [
            'payment_method_types' => ['card'],
            'amount' => 1999,
            'currency' => 'usd',
        ],
        'bancontact' => [
            'payment_method_types' => ['bancontact'],
            'amount' => 1999,
            'currency' => 'eur',
        ],
        'becs-debit' => [
            'payment_method_types' => ['au_becs_debit'],
            'amount' => 1999,
            'currency' => 'aud',
        ],
        'boleto' => [
            'payment_method_types' => ['boleto'],
            'amount' => 5000,
            'currency' => 'brl',
        ],
        'card' => [
            'payment_method_types' => ['card'],
            'amount' => 1999,
            'currency' => 'usd',
        ],
        'eps' => [
            'payment_method_types' => ['eps'],
            'amount' => 1999,
            'currency' => 'eur',
        ],
        'fpx' => [
            'payment_method_types' => ['fpx'],
            'amount' => 1999,
            'currency' => 'myr',
        ],
        'giropay' => [
            'payment_method_types' => ['giropay'],
            'amount' => 1999,
            'currency' => 'eur',
        ],
        'google-pay' => [
            'payment_method_types' => ['card'],
            'amount' => 1999,
            'currency' => 'usd',
        ],
        'grabpay' => [
            'payment_method_types' => ['grabpay'],
            'amount' => 1999,
            'currency' => 'myr',
        ],
        'ideal' => [
            'payment_method_types' => ['ideal'],
            'amount' => 1999,
            'currency' => 'eur',
        ],
        'konbini' => [
            'payment_method_types' => ['konbini'],
            'amount' => 1999,
            'currency' => 'jpy',
            'payment_method_options' => [
                'konbini' => [
                    'product_description' => 'Tシャツ',
                    'expires_after_days' => 3,
                ],
            ],
        ],
        'link' => [
            'payment_method_types' => ['link', 'card'],
            'amount' => 1999,
            'currency' => 'usd',
        ],
        'oxxo' => [
            'payment_method_types' => ['oxxo'],
            'amount' => 1999,
            'currency' => 'mxn',
        ],
        'p24' => [
            'payment_method_types' => ['p24'],
            'amount' => 1999,
            'currency' => 'eur',
        ],
        'sepa-debit' => [
            'payment_method_types' => ['sepa_debit'],
            'amount' => 1999,
            'currency' => 'eur',
        ],
        'sofort' => [
            'payment_method_types' => ['sofort'],
            'amount' => 1999,
            'currency' => 'eur',
        ],
    ];

    /**
     * @return array<string, mixed>
     */
    public static function params(string $page): array
    {
        if (!isset(self::PARAMS[$page])) {
            throw new \InvalidArgumentException('Unknown payment page: ' . $page);
        }

        return self::PARAMS[$page];
    }
}
