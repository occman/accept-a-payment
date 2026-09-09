import {NextRequest} from 'next/server';
import {POST} from '@/app/api/create-payment-intent/route';
import {createStripeMock} from './helpers/stripe-mock';

const mockStripe = createStripeMock();

jest.mock('stripe', () => ({
  __esModule: true,
  default: jest.fn(() => mockStripe),
}));

function post(body: unknown) {
  return POST(
    new NextRequest('http://localhost:4242/api/create-payment-intent', {
      method: 'POST',
      headers: {'content-type': 'application/json'},
      body: typeof body === 'string' ? body : JSON.stringify(body),
    })
  );
}

beforeEach(() => {
  jest.clearAllMocks();
  mockStripe.paymentIntents.create.mockResolvedValue({
    client_secret: 'pi_123_secret_456',
    next_action: null,
  });
});

describe('POST /api/create-payment-intent', () => {
  it('creates a PaymentIntent for the requested type and currency', async () => {
    const response = await post({paymentMethodType: 'card', currency: 'usd'});

    expect(response.status).toBe(200);
    expect(await response.json()).toEqual({
      clientSecret: 'pi_123_secret_456',
      nextAction: null,
    });
    expect(mockStripe.paymentIntents.create).toHaveBeenCalledTimes(1);
    expect(mockStripe.paymentIntents.create).toHaveBeenCalledWith({
      payment_method_types: ['card'],
      amount: 5999,
      currency: 'usd',
    });
    expect(mockStripe.customers.create).not.toHaveBeenCalled();
    expect(mockStripe.tax.calculations.create).not.toHaveBeenCalled();
  });

  it('returns the next action when the payment method requires one', async () => {
    const nextAction = {
      type: 'redirect_to_url',
      redirect_to_url: {url: 'https://hooks.stripe.com/redirect'},
    };
    mockStripe.paymentIntents.create.mockResolvedValue({
      client_secret: 'pi_123_secret_456',
      next_action: nextAction,
    });

    const response = await post({paymentMethodType: 'ideal', currency: 'eur'});

    expect(response.status).toBe(200);
    expect(await response.json()).toEqual({
      clientSecret: 'pi_123_secret_456',
      nextAction,
    });
    expect(mockStripe.paymentIntents.create).toHaveBeenCalledWith({
      payment_method_types: ['ideal'],
      amount: 5999,
      currency: 'eur',
    });
  });

  it('allows card payments alongside link', async () => {
    const response = await post({paymentMethodType: 'link', currency: 'usd'});

    expect(response.status).toBe(200);
    expect(mockStripe.paymentIntents.create).toHaveBeenCalledWith({
      payment_method_types: ['link', 'card'],
      amount: 5999,
      currency: 'usd',
    });
  });

  it('adds mandate options for acss_debit', async () => {
    const response = await post({
      paymentMethodType: 'acss_debit',
      currency: 'cad',
    });

    expect(response.status).toBe(200);
    expect(mockStripe.paymentIntents.create).toHaveBeenCalledWith({
      payment_method_types: ['acss_debit'],
      amount: 5999,
      currency: 'cad',
      payment_method_options: {
        acss_debit: {
          mandate_options: {
            payment_schedule: 'sporadic',
            transaction_type: 'personal',
          },
        },
      },
    });
  });

  it('adds default konbini options', async () => {
    const response = await post({
      paymentMethodType: 'konbini',
      currency: 'jpy',
    });

    expect(response.status).toBe(200);
    expect(mockStripe.paymentIntents.create).toHaveBeenCalledWith({
      payment_method_types: ['konbini'],
      amount: 5999,
      currency: 'jpy',
      payment_method_options: {
        konbini: {
          product_description: 'Tシャツ',
          expires_after_days: 3,
        },
      },
    });
  });

  it('reuses the supplied customer for customer_balance payments', async () => {
    const response = await post({
      paymentMethodType: 'customer_balance',
      currency: 'eur',
      customerId: 'cus_existing',
    });

    expect(response.status).toBe(200);
    expect(mockStripe.customers.create).not.toHaveBeenCalled();
    expect(mockStripe.paymentIntents.create).toHaveBeenCalledWith({
      payment_method_types: ['customer_balance'],
      amount: 5999,
      currency: 'eur',
      payment_method_data: {type: 'customer_balance'},
      confirm: true,
      customer: 'cus_existing',
    });
  });

  it('creates a customer for customer_balance payments when none is given', async () => {
    mockStripe.customers.create.mockResolvedValue({id: 'cus_new'});

    const response = await post({
      paymentMethodType: 'customer_balance',
      currency: 'eur',
    });

    expect(response.status).toBe(200);
    expect(mockStripe.customers.create).toHaveBeenCalledTimes(1);
    expect(mockStripe.paymentIntents.create).toHaveBeenCalledWith(
      expect.objectContaining({
        payment_method_types: ['customer_balance'],
        payment_method_data: {type: 'customer_balance'},
        confirm: true,
        customer: 'cus_new',
      })
    );
  });

  it('lets the client override payment_method_options', async () => {
    const paymentMethodOptions = {
      acss_debit: {
        mandate_options: {
          payment_schedule: 'interval',
          interval_description: 'First day of every month',
          transaction_type: 'business',
        },
      },
    };

    const response = await post({
      paymentMethodType: 'acss_debit',
      currency: 'cad',
      paymentMethodOptions,
    });

    expect(response.status).toBe(200);
    expect(mockStripe.paymentIntents.create).toHaveBeenCalledWith({
      payment_method_types: ['acss_debit'],
      amount: 5999,
      currency: 'cad',
      payment_method_options: paymentMethodOptions,
    });
  });

  it('rejects a request with no parameters with the Stripe error message', async () => {
    mockStripe.paymentIntents.create.mockRejectedValue(
      new Error('You must provide a currency.')
    );

    const response = await post({});

    expect(response.status).toBe(400);
    expect(await response.json()).toEqual({
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

    const response = await post({
      paymentMethodType: 'not_a_type',
      currency: 'usd',
    });

    expect(response.status).toBe(400);
    expect(await response.json()).toEqual({
      error: {message: 'Invalid payment method type: not_a_type'},
    });
  });

  it('surfaces Stripe API errors as a 400', async () => {
    const stripeError = Object.assign(new Error('Your card was declined.'), {
      type: 'StripeCardError',
    });
    mockStripe.paymentIntents.create.mockRejectedValue(stripeError);

    const response = await post({paymentMethodType: 'card', currency: 'usd'});

    expect(response.status).toBe(400);
    expect(await response.json()).toEqual({
      error: {message: 'Your card was declined.'},
    });
  });

  it('rejects a malformed JSON body as a 400 without calling Stripe', async () => {
    const response = await post('{not json');

    expect(response.status).toBe(400);
    const body = await response.json();
    expect(typeof body.error.message).toBe('string');
    expect(mockStripe.paymentIntents.create).not.toHaveBeenCalled();
  });
});
