<?php

namespace Tests;

final class IndexTest extends ScriptTestCase
{
    public function testLinksToThePaymentMethodPages(): void
    {
        $result = $this->runScript('index.php');

        self::assertSame(200, $result->status);
        self::assertStringContainsString('<h1>Accept a payment</h1>', $result->body);

        $linkedPages = [
            'card.php', 'link.php', 'becs-debit.php', 'sepa-debit.php', 'bancontact.php',
            'eps.php', 'fpx.php', 'giropay.php', 'ideal.php', 'p24.php', 'sofort.php',
            'afterpay-clearpay.php', 'boleto.php', 'oxxo.php', 'konbini.php', 'alipay.php',
            'apple-pay.php', 'google-pay.php', 'grabpay.php',
        ];
        foreach ($linkedPages as $page) {
            self::assertFileExists(self::PUBLIC_DIR . '/' . $page);
            self::assertStringContainsString('href="/' . $page . '"', $result->body, "index.php should link to {$page}");
        }
    }
}
