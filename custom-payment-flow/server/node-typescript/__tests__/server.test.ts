import {jest} from '@jest/globals';
import type {Express} from 'express';
import request from 'supertest';

const mockStripe = {
  paymentIntents: {
    create: jest.fn<() => Promise<unknown>>(),
    retrieve: jest.fn<() => Promise<unknown>>(),
  },
  customers: {
    create: jest.fn<() => Promise<unknown>>(),
  },
  webhooks: {
    constructEvent: jest.fn<() => unknown>(),
  },
  tax: {
    calculations: {
      create: jest.fn<() => Promise<unknown>>(),
    },
  },
};

// The SDK is replaced entirely so the suite never reaches the network and no
// API keys are required.
jest.unstable_mockModule('stripe', () => ({
  default: jest.fn(() => mockStripe),
}));

let app: Express;

beforeAll(async () => {
  process.env.STRIPE_SECRET_KEY = 'sk_test_123';
  process.env.STRIPE_PUBLISHABLE_KEY = 'pk_test_123';
  process.env.STRIPE_WEBHOOK_SECRET = 'whsec_123';
  process.env.STATIC_DIR = '../../client/html';
  ({default: app} = await import('../src/server'));
});

beforeEach(() => {
  jest.clearAllMocks();
});

describe('static pages', () => {
  it('serves the index page at the root', async () => {
    const response = await request(app).get('/');

    expect(response.status).toBe(200);
    expect(response.headers['content-type']).toMatch(/text\/html/);
  });

  it('serves the success page', async () => {
    const response = await request(app).get('/success');

    expect(response.status).toBe(200);
    expect(response.headers['content-type']).toMatch(/text\/html/);
  });
});

describe('GET /config', () => {
  it('returns the publishable key', async () => {
    const response = await request(app).get('/config');

    expect(response.status).toBe(200);
    expect(response.body).toEqual({publishableKey: 'pk_test_123'});
  });
});

describe('POST /create-payment-intent', () => {
  it('creates a PaymentIntent for the requested type and currency', async () => {
    mockStripe.paymentIntents.create.mockResolvedValue({
      client_secret: 'pi_123_secret_456',
      next_action: null,
    });

    const response = await request(app)
      .post('/create-payment-intent')
      .send({paymentMethodType: 'card', currency: 'usd'});

    expect(response.status).toBe(200);
    expect(response.body).toEqual({
      clientSecret: 'pi_123_secret_456',
      nextAction: null,
    });
    expect(mockStripe.paymentIntents.create).toHaveBeenCalledWith({
      payment_method_types: ['card'],
      amount: 5999,
      currency: 'usd',
    });
  });

  it('returns the next action when the payment method requires one', async () => {
    const nextAction = {type: 'konbini_display_details'};
    mockStripe.paymentIntents.create.mockResolvedValue({
      client_secret: 'pi_123_secret_456',
      next_action: nextAction,
    });

    const response = await request(app)
      .post('/create-payment-intent')
      .send({paymentMethodType: 'konbini', currency: 'jpy'});

    expect(response.status).toBe(200);
    expect(response.body.nextAction).toEqual(nextAction);
    expect(mockStripe.paymentIntents.create).toHaveBeenCalledWith(
      expect.objectContaining({payment_method_types: ['konbini']})
    );
  });

  it('allows card payments alongside link', async () => {
    mockStripe.paymentIntents.create.mockResolvedValue({
      client_secret: 'pi_123_secret_456',
    });

    await request(app)
      .post('/create-payment-intent')
      .send({paymentMethodType: 'link', currency: 'usd'});

    expect(mockStripe.paymentIntents.create).toHaveBeenCalledWith(
      expect.objectContaining({payment_method_types: ['link', 'card']})
    );
  });

  it('adds mandate options for acss_debit', async () => {
    mockStripe.paymentIntents.create.mockResolvedValue({
      client_secret: 'pi_123_secret_456',
    });

    await request(app)
      .post('/create-payment-intent')
      .send({paymentMethodType: 'acss_debit', currency: 'cad'});

    expect(mockStripe.paymentIntents.create).toHaveBeenCalledWith(
      expect.objectContaining({
        payment_method_options: {
          acss_debit: {
            mandate_options: {
              payment_schedule: 'sporadic',
              transaction_type: 'personal',
            },
          },
        },
      })
    );
  });

  it('reuses the supplied customer for customer_balance payments', async () => {
    mockStripe.paymentIntents.create.mockResolvedValue({
      client_secret: 'pi_123_secret_456',
    });

    await request(app).post('/create-payment-intent').send({
      paymentMethodType: 'customer_balance',
      currency: 'eur',
      customerId: 'cus_existing',
    });

    expect(mockStripe.customers.create).not.toHaveBeenCalled();
    expect(mockStripe.paymentIntents.create).toHaveBeenCalledWith(
      expect.objectContaining({
        confirm: true,
        customer: 'cus_existing',
        payment_method_data: {type: 'customer_balance'},
      })
    );
  });

  it('creates a customer for customer_balance payments when none is given', async () => {
    mockStripe.customers.create.mockResolvedValue({id: 'cus_created'});
    mockStripe.paymentIntents.create.mockResolvedValue({
      client_secret: 'pi_123_secret_456',
    });

    await request(app)
      .post('/create-payment-intent')
      .send({paymentMethodType: 'customer_balance', currency: 'eur'});

    expect(mockStripe.customers.create).toHaveBeenCalledTimes(1);
    expect(mockStripe.paymentIntents.create).toHaveBeenCalledWith(
      expect.objectContaining({customer: 'cus_created'})
    );
  });

  it('lets the client override payment_method_options', async () => {
    mockStripe.paymentIntents.create.mockResolvedValue({
      client_secret: 'pi_123_secret_456',
    });
    const paymentMethodOptions = {card: {request_three_d_secure: 'any'}};

    await request(app).post('/create-payment-intent').send({
      paymentMethodType: 'card',
      currency: 'usd',
      paymentMethodOptions,
    });

    expect(mockStripe.paymentIntents.create).toHaveBeenCalledWith(
      expect.objectContaining({payment_method_options: paymentMethodOptions})
    );
  });

  it('rejects a request with no parameters with the Stripe error message', async () => {
    mockStripe.paymentIntents.create.mockRejectedValue(
      new Error('You must provide a currency.')
    );

    const response = await request(app).post('/create-payment-intent').send({});

    expect(response.status).toBe(400);
    expect(response.body).toEqual({
      error: {message: 'You must provide a currency.'},
    });
    expect(mockStripe.paymentIntents.create).toHaveBeenCalledWith({
      payment_method_types: [undefined],
      amount: 5999,
      currency: undefined,
    });
  });

  it('rejects an invalid payment method type with the Stripe error message', async () => {
    mockStripe.paymentIntents.create.mockRejectedValue(
      new Error('Invalid payment method type: not_a_type')
    );

    const response = await request(app)
      .post('/create-payment-intent')
      .send({paymentMethodType: 'not_a_type', currency: 'usd'});

    expect(response.status).toBe(400);
    expect(response.body.error.message).toBe(
      'Invalid payment method type: not_a_type'
    );
  });

  it('surfaces Stripe API errors as a 400', async () => {
    const stripeError = Object.assign(new Error('Your card was declined.'), {
      type: 'StripeCardError',
    });
    mockStripe.paymentIntents.create.mockRejectedValue(stripeError);

    const response = await request(app)
      .post('/create-payment-intent')
      .send({paymentMethodType: 'card', currency: 'usd'});

    expect(response.status).toBe(400);
    expect(response.body).toEqual({
      error: {message: 'Your card was declined.'},
    });
  });
});

describe('GET /payment/next', () => {
  it('redirects to the success page with the intent client secret', async () => {
    mockStripe.paymentIntents.retrieve.mockResolvedValue({
      client_secret: 'pi_123_secret_456',
    });

    const response = await request(app)
      .get('/payment/next')
      .query({payment_intent: 'pi_123'});

    expect(response.status).toBe(302);
    expect(response.headers.location).toBe(
      '/success?payment_intent_client_secret=pi_123_secret_456'
    );
    expect(mockStripe.paymentIntents.retrieve).toHaveBeenCalledWith('pi_123', {
      expand: ['payment_method'],
    });
  });
});

describe('POST /webhook', () => {
  it('verifies the signature against the raw body and handles the event', async () => {
    mockStripe.webhooks.constructEvent.mockReturnValue({
      type: 'payment_intent.succeeded',
      data: {object: {object: 'payment_intent', status: 'succeeded'}},
    });
    const payload = {type: 'payment_intent.succeeded', data: {}};

    const response = await request(app)
      .post('/webhook')
      .set('stripe-signature', 't=1,v1=signature')
      .send(payload);

    expect(response.status).toBe(200);
    expect(mockStripe.webhooks.constructEvent).toHaveBeenCalledWith(
      Buffer.from(JSON.stringify(payload)),
      't=1,v1=signature',
      'whsec_123'
    );
  });

  it('handles a failed payment event', async () => {
    mockStripe.webhooks.constructEvent.mockReturnValue({
      type: 'payment_intent.payment_failed',
      data: {
        object: {object: 'payment_intent', status: 'requires_payment_method'},
      },
    });

    const response = await request(app)
      .post('/webhook')
      .set('stripe-signature', 't=1,v1=signature')
      .send({type: 'payment_intent.payment_failed'});

    expect(response.status).toBe(200);
  });

  it('acknowledges events it does not act on', async () => {
    mockStripe.webhooks.constructEvent.mockReturnValue({
      type: 'charge.refunded',
      data: {object: {}},
    });

    const response = await request(app)
      .post('/webhook')
      .set('stripe-signature', 't=1,v1=signature')
      .send({type: 'charge.refunded'});

    expect(response.status).toBe(200);
  });

  it('rejects a request whose signature does not verify', async () => {
    mockStripe.webhooks.constructEvent.mockImplementation(() => {
      throw new Error(
        'No signatures found matching the expected signature for payload.'
      );
    });

    const response = await request(app)
      .post('/webhook')
      .set('stripe-signature', 't=1,v1=bad')
      .send({type: 'payment_intent.succeeded'});

    expect(response.status).toBe(400);
  });
});
