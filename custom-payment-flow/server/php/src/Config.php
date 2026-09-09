<?php

namespace App;

/**
 * Loads and validates the .env configuration used by the sample server.
 */
final class Config
{
    public function __construct(
        public readonly string $publishableKey,
        public readonly string $secretKey,
        public readonly string $webhookSecret,
    ) {
    }

    /**
     * @param string $dir directory containing the .env file (the server root)
     *
     * @throws MissingEnvFileException when $dir/.env does not exist
     * @throws InvalidEnvException     when STRIPE_SECRET_KEY is missing or empty
     */
    public static function load(string $dir): self
    {
        if (!file_exists($dir . '/.env')) {
            throw new MissingEnvFileException('No .env file found in ' . $dir);
        }

        \Dotenv\Dotenv::createImmutable($dir)->load();

        $secretKey = $_ENV['STRIPE_SECRET_KEY'] ?? '';
        if ('' === $secretKey) {
            throw new InvalidEnvException('STRIPE_SECRET_KEY is not configured in .env');
        }

        return new self(
            $_ENV['STRIPE_PUBLISHABLE_KEY'] ?? '',
            $secretKey,
            $_ENV['STRIPE_WEBHOOK_SECRET'] ?? '',
        );
    }
}
