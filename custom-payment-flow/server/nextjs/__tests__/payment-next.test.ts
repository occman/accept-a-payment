import {NextRequest} from 'next/server';
import {GET} from '@/app/api/payment/next/route';
import {createStripeMock} from './helpers/stripe-mock';

const mockStripe = createStripeMock();

jest.mock('stripe', () => ({
  __esModule: true,
  default: jest.fn(() => mockStripe),
}));

function get(query: string) {
  return GET(new NextRequest(`http://localhost:4242/api/payment/next${query}`));
}

beforeEach(() => {
  jest.clearAllMocks();
});

describe('GET /api/payment/next', () => {
  it('redirects to the success page with the intent client secret', async () => {
    mockStripe.paymentIntents.retrieve.mockResolvedValue({
      client_secret: 'pi_123_secret_456',
    });

    const response = await get('?payment_intent=pi_123');

    expect(response.status).toBe(307);
    expect(response.headers.get('location')).toBe(
      'http://localhost:4242/success?payment_intent_client_secret=pi_123_secret_456'
    );
    expect(mockStripe.paymentIntents.retrieve).toHaveBeenCalledWith('pi_123', {
      expand: ['payment_method'],
    });
  });

  it('rejects a request without a payment_intent', async () => {
    const response = await get('');

    expect(response.status).toBe(400);
    expect(await response.json()).toEqual({
      error: 'Missing payment_intent parameter',
    });
    expect(mockStripe.paymentIntents.retrieve).not.toHaveBeenCalled();
  });

  it('surfaces Stripe API errors as a 400', async () => {
    mockStripe.paymentIntents.retrieve.mockRejectedValue(
      new Error('No such payment_intent: pi_missing')
    );

    const response = await get('?payment_intent=pi_missing');

    expect(response.status).toBe(400);
    expect(await response.json()).toEqual({
      error: 'No such payment_intent: pi_missing',
    });
  });
});
