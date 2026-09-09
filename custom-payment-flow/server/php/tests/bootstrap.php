<?php

require_once __DIR__ . '/../vendor/autoload.php';

// The scripts read their configuration from $_ENV (populated by phpdotenv at
// runtime). Provide offline placeholders; the Stripe client itself is replaced
// by a test double, so no request ever leaves the process.
$_ENV['STRIPE_PUBLISHABLE_KEY'] = 'pk_test_123';
$_ENV['STRIPE_SECRET_KEY'] = 'sk_test_123';
$_ENV['STRIPE_WEBHOOK_SECRET'] = 'whsec_test_123';
