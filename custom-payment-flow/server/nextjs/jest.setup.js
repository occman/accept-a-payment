// Placeholder credentials so the route handlers can be exercised offline. The
// Stripe SDK itself is mocked in each test file, so these are never sent
// anywhere.
process.env.STRIPE_SECRET_KEY = 'sk_test_123';
process.env.NEXT_PUBLIC_STRIPE_PUBLISHABLE_KEY = 'pk_test_123';
process.env.STRIPE_WEBHOOK_SECRET = 'whsec_123';
