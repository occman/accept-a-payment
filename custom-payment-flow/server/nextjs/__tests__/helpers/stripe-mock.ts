// A hand-rolled stand-in for the Stripe SDK covering every call the route
// handlers make. Test files pass this to `jest.mock('stripe', ...)` so the
// suite never reaches the network and no API keys are required.
export function createStripeMock() {
  return {
    paymentIntents: {
      create: jest.fn(),
      retrieve: jest.fn(),
    },
    customers: {
      create: jest.fn(),
    },
    webhooks: {
      constructEvent: jest.fn(),
    },
    tax: {
      calculations: {
        create: jest.fn(),
      },
    },
  };
}

export type StripeMock = ReturnType<typeof createStripeMock>;
