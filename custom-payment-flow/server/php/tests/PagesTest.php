<?php

namespace App\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PagesTest extends TestCase
{
    private const PUBLIC_DIR = __DIR__ . '/../public';

    public function testIndexPageRendersPaymentMethodLinks(): void
    {
        ob_start();
        require self::PUBLIC_DIR . '/index.php';
        $html = ob_get_clean();

        self::assertStringContainsString('Accept a payment', $html);
        self::assertStringContainsString('href="/card.php"', $html);
        self::assertStringContainsString('href="/konbini.php"', $html);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function paymentPages(): iterable
    {
        foreach (glob(self::PUBLIC_DIR . '/*.php') as $path) {
            $slug = basename($path, '.php');
            if (in_array($slug, ['index', 'shared', 'webhook', 'return'], true)) {
                continue;
            }
            yield $slug => [$slug];
        }
    }

    /**
     * Guards that each payment page goes through the shared, tested handler
     * with its own slug.
     */
    #[DataProvider('paymentPages')]
    public function testPaymentPageCreatesIntentThroughSharedHandler(string $slug): void
    {
        $source = file_get_contents(self::PUBLIC_DIR . '/' . $slug . '.php');

        self::assertStringContainsString('App\Checkout::createIntent', $source);
        self::assertStringContainsString("PaymentMethods::params('" . $slug . "')", $source);
    }

    /**
     * Every payment page file on disk has a matching PaymentMethods entry,
     * and every PaymentMethods entry is backed by a page.
     */
    public function testPaymentPagesAndMethodParamsStayInSync(): void
    {
        $pages = [];
        foreach (self::paymentPages() as $slug => $_) {
            $pages[] = $slug;
        }

        $paramPages = [];
        foreach (PaymentMethodsTest::pages() as $page => $_) {
            $paramPages[] = $page;
            self::assertFileExists(self::PUBLIC_DIR . '/' . $page . '.php');
        }

        sort($pages);
        sort($paramPages);
        self::assertSame($pages, $paramPages);
    }
}
