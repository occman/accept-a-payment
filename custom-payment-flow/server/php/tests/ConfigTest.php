<?php

namespace App\Tests;

use App\Config;
use App\InvalidEnvException;
use App\MissingEnvFileException;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    private const ENV_KEYS = [
        'STRIPE_PUBLISHABLE_KEY',
        'STRIPE_SECRET_KEY',
        'STRIPE_WEBHOOK_SECRET',
    ];

    private string $dir;

    /** @var array<string, string> */
    private array $envBackup;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/php-config-test-' . bin2hex(random_bytes(6));
        mkdir($this->dir);

        // Dotenv is immutable, so STRIPE_* variables already present in the
        // environment would win over the .env file under test. Clear them.
        $this->envBackup = $_ENV;
        foreach (self::ENV_KEYS as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        }
    }

    protected function tearDown(): void
    {
        $_ENV = $this->envBackup;

        foreach (scandir($this->dir) ?: [] as $file) {
            if ('.' !== $file && '..' !== $file) {
                unlink($this->dir . '/' . $file);
            }
        }
        rmdir($this->dir);
    }

    public function testMissingEnvFileThrows(): void
    {
        $this->expectException(MissingEnvFileException::class);

        Config::load($this->dir);
    }

    public function testMissingSecretKeyThrows(): void
    {
        file_put_contents($this->dir . '/.env', "STRIPE_PUBLISHABLE_KEY=pk_test_placeholder\n");

        $this->expectException(InvalidEnvException::class);

        Config::load($this->dir);
    }

    public function testEmptySecretKeyThrows(): void
    {
        file_put_contents($this->dir . '/.env', "STRIPE_SECRET_KEY=\n");

        $this->expectException(InvalidEnvException::class);

        Config::load($this->dir);
    }

    public function testLoadReturnsConfiguredValues(): void
    {
        file_put_contents($this->dir . '/.env', implode("\n", [
            'STRIPE_PUBLISHABLE_KEY=pk_test_placeholder',
            'STRIPE_SECRET_KEY=sk_test_placeholder',
            'STRIPE_WEBHOOK_SECRET=whsec_placeholder',
            'DOMAIN=http://localhost:4242',
            '',
        ]));

        $config = Config::load($this->dir);

        self::assertSame('pk_test_placeholder', $config->publishableKey);
        self::assertSame('sk_test_placeholder', $config->secretKey);
        self::assertSame('whsec_placeholder', $config->webhookSecret);
    }
}
