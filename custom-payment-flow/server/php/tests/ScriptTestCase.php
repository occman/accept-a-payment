<?php

namespace Tests;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Stripe\Service\PaymentIntentService;
use Stripe\StripeClient;

/**
 * Executes the plain scripts under public/ in-process, the way the PHP
 * built-in web server would, but with `$stripe` bound to a mock client so
 * nothing talks to the Stripe API.
 */
abstract class ScriptTestCase extends TestCase
{
    protected const PUBLIC_DIR = __DIR__ . '/../public';

    /** @var PaymentIntentService&MockObject */
    protected PaymentIntentService $paymentIntents;

    /** @var StripeClient&MockObject */
    protected StripeClient $stripe;

    private string $errorLogFile;

    private string|false $previousErrorLog;

    protected function setUp(): void
    {
        $this->paymentIntents = $this->createMock(PaymentIntentService::class);

        // Services on StripeClient are resolved through __get(), so route the
        // `paymentIntents` property to the service mock.
        $this->stripe = $this->createMock(StripeClient::class);
        $this->stripe->method('__get')
            ->with('paymentIntents')
            ->willReturn($this->paymentIntents);

        $this->errorLogFile = tempnam(sys_get_temp_dir(), 'phpunit-error-log-');
        $this->previousErrorLog = ini_set('error_log', $this->errorLogFile);

        http_response_code(200);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', (string) $this->previousErrorLog);
        @unlink($this->errorLogFile);
        http_response_code(200);
        $_GET = [];
        unset($_SERVER['HTTP_STRIPE_SIGNATURE']);
    }

    /**
     * Includes public/<script> with `$stripe` (and any extra variables) in
     * scope and captures the HTTP status code and the rendered body.
     *
     * @param array<string, mixed> $vars
     */
    protected function runScript(string $script, array $vars = []): ScriptResult
    {
        $vars['stripe'] = $this->stripe;

        $run = static function (string $__file, array $__vars): void {
            extract($__vars);
            include $__file;
        };

        // PHPUnit has already written to stdout, so header() in the scripts
        // raises "Cannot modify header information" on the CLI. Swallow that
        // one warning and forward everything else to PHPUnit's handler.
        $previous = null;
        $previous = set_error_handler(
            static function (int $errno, string $errstr, string $errfile, int $errline) use (&$previous): bool {
                if (str_starts_with($errstr, 'Cannot modify header information')) {
                    return true;
                }

                return $previous !== null && (bool) $previous($errno, $errstr, $errfile, $errline);
            }
        );

        ob_start();
        try {
            $run(self::PUBLIC_DIR . '/' . $script, $vars);
        } finally {
            $body = ob_get_clean();
            restore_error_handler();
        }

        return new ScriptResult(http_response_code(), $body);
    }

    protected function errorLog(): string
    {
        return (string) file_get_contents($this->errorLogFile);
    }
}
