# Accept a payment – custom payment flow (Next.js)

Next.js App Router server for the custom payment flow sample. API routes live
under `app/api/` and the Stripe client is created in `lib/stripe.ts`.

## Running the app

```bash
cp .env.local.example .env.local   # then fill in your Stripe keys
npm install
npm run dev                        # http://localhost:4242
```

## Running tests

The unit tests use [Vitest](https://vitest.dev) and mock the `stripe` module,
so they run fully offline with placeholder keys (no Stripe account needed).

```bash
npm test               # run the suite
npm run test:coverage  # run the suite and print a coverage report
```

Tests live in `__tests__/` and cover the `config`, `create-payment-intent`,
`webhook` and `payment/next` route handlers plus `lib/stripe.ts`. The same
command runs in CI via `.github/workflows/nextjs-unit-tests.yml`.
