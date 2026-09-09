import Stripe from 'stripe';
import {getStripe} from '@/lib/stripe';
import {createStripeMock} from './helpers/stripe-mock';

const mockStripe = createStripeMock();

jest.mock('stripe', () => ({
  __esModule: true,
  default: jest.fn(() => mockStripe),
}));

describe('getStripe', () => {
  it('builds the client from STRIPE_SECRET_KEY and reuses it', () => {
    const first = getStripe();
    const second = getStripe();

    expect(first).toBe(mockStripe);
    expect(second).toBe(first);
    expect(Stripe).toHaveBeenCalledTimes(1);
    expect(Stripe).toHaveBeenCalledWith('sk_test_123', {
      apiVersion: '2025-12-15.clover',
      appInfo: {
        name: 'stripe-samples/accept-a-payment/custom-payment-flow',
        version: '0.0.2',
        url: 'https://github.com/stripe-samples/accept-a-payment',
      },
    });
  });
});
