import {NextRequest} from 'next/server';
import {POST} from '@/app/api/webhook/route';
import {createStripeMock} from './helpers/stripe-mock';

const mockStripe = createStripeMock();

jest.mock('stripe', () => ({
  __esModule: true,
  default: jest.fn(() => mockStripe),
}));

const SIGNATURE = 't=1,v1=signature';

function post(payload: unknown, signature: string | null = SIGNATURE) {
  const headers: Record<string, string> = {
    'content-type': 'application/json',
  };
  if (signature) {
    headers['stripe-signature'] = signature;
  }
  return POST(
    new NextRequest('http://localhost:4242/api/webhook', {
      method: 'POST',
      headers,
      body: JSON.stringify(payload),
    })
  );
}

let log: jest.SpyInstance;

beforeEach(() => {
  jest.clearAllMocks();
  process.env.STRIPE_WEBHOOK_SECRET = 'whsec_123';
  log = jest.spyOn(console, 'log').mockImplementation(() => {});
});

afterEach(() => {
  log.mockRestore();
});

describe('POST /api/webhook', () => {
  it('verifies the signature against the raw body and handles a captured payment', async () => {
    mockStripe.webhooks.constructEvent.mockReturnValue({
      type: 'payment_intent.succeeded',
      data: {object: {id: 'pi_123'}},
    });
    const payload = {type: 'payment_intent.succeeded', data: {}};

    const response = await post(payload);

    expect(response.status).toBe(200);
    expect(await response.json()).toEqual({received: true});
    expect(mockStripe.webhooks.constructEvent).toHaveBeenCalledWith(
      JSON.stringify(payload),
      SIGNATURE,
      'whsec_123'
    );
    expect(log).toHaveBeenCalledWith('Payment captured!');
  });

  it('handles a failed payment event', async () => {
    mockStripe.webhooks.constructEvent.mockReturnValue({
      type: 'payment_intent.payment_failed',
      data: {object: {id: 'pi_123'}},
    });

    const response = await post({type: 'payment_intent.payment_failed'});

    expect(response.status).toBe(200);
    expect(await response.json()).toEqual({received: true});
    expect(log).toHaveBeenCalledWith('Payment failed.');
  });

  it('acknowledges events it does not act on', async () => {
    mockStripe.webhooks.constructEvent.mockReturnValue({
      type: 'charge.refunded',
      data: {object: {id: 'ch_123'}},
    });

    const response = await post({type: 'charge.refunded'});

    expect(response.status).toBe(200);
    expect(await response.json()).toEqual({received: true});
    expect(log).not.toHaveBeenCalled();
  });

  it('rejects an event whose signature does not verify', async () => {
    mockStripe.webhooks.constructEvent.mockImplementation(() => {
      throw new Error('No signatures found matching the expected signature');
    });

    const response = await post({type: 'payment_intent.succeeded'});

    expect(response.status).toBe(400);
    expect(await response.json()).toEqual({
      error: 'No signatures found matching the expected signature',
    });
    expect(log).toHaveBeenCalledWith(
      'Webhook signature verification failed: No signatures found matching the expected signature'
    );
  });

  it('reads the event straight from the body when signing is not configured', async () => {
    delete process.env.STRIPE_WEBHOOK_SECRET;

    const response = await post({type: 'payment_intent.succeeded'}, null);

    expect(response.status).toBe(200);
    expect(await response.json()).toEqual({received: true});
    expect(mockStripe.webhooks.constructEvent).not.toHaveBeenCalled();
    expect(log).toHaveBeenCalledWith('Payment captured!');
  });
});
