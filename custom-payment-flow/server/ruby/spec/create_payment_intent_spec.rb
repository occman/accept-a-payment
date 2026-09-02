RSpec.describe 'POST /create-payment-intent' do
  let(:payment_intent) { double('Stripe::PaymentIntent', client_secret: 'pi_123_secret_abc') }

  def post_intent(body)
    post '/create-payment-intent', body.to_json, 'CONTENT_TYPE' => 'application/json'
  end

  it 'creates a card PaymentIntent and returns its client secret' do
    expect(Stripe::PaymentIntent).to receive(:create)
      .with({ payment_method_types: ['card'], amount: 5999, currency: 'usd' })
      .and_return(payment_intent)

    post_intent(paymentMethodType: 'card', currency: 'usd')

    expect(last_response.status).to eq(200)
    expect(last_response.content_type).to include('application/json')
    expect(json_body).to eq('clientSecret' => 'pi_123_secret_abc')
  end

  it 'adds card to payment_method_types for link' do
    expect(Stripe::PaymentIntent).to receive(:create)
      .with(hash_including(payment_method_types: %w[link card], currency: 'usd'))
      .and_return(payment_intent)

    post_intent(paymentMethodType: 'link', currency: 'usd')

    expect(last_response.status).to eq(200)
    expect(json_body).to eq('clientSecret' => 'pi_123_secret_abc')
  end

  it 'adds mandate options for acss_debit' do
    expect(Stripe::PaymentIntent).to receive(:create).with(
      {
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
      }
    ).and_return(payment_intent)

    post_intent(paymentMethodType: 'acss_debit', currency: 'cad')

    expect(last_response.status).to eq(200)
  end

  it 'does not add payment_method_options for non-ACSS methods' do
    expect(Stripe::PaymentIntent).to receive(:create) do |params|
      expect(params).not_to have_key(:payment_method_options)
      expect(params[:payment_method_types]).to eq(['ideal'])
      payment_intent
    end

    post_intent(paymentMethodType: 'ideal', currency: 'eur')

    expect(last_response.status).to eq(200)
  end

  it 'forwards missing params to Stripe unvalidated and surfaces its error as 400' do
    expect(Stripe::PaymentIntent).to receive(:create)
      .with({ payment_method_types: [nil], amount: 5999, currency: nil })
      .and_raise(stripe_error('Missing required param: currency.', 'currency'))

    post_intent({})

    expect(last_response.status).to eq(400)
    expect(last_response.content_type).to include('application/json')
    expect(json_body).to eq('error' => { 'message' => 'Missing required param: currency.' })
  end

  it 'returns 400 with the Stripe error message when the API call fails' do
    expect(Stripe::PaymentIntent).to receive(:create)
      .and_raise(stripe_error('Invalid currency: xyz', 'currency'))

    post_intent(paymentMethodType: 'card', currency: 'xyz')

    expect(last_response.status).to eq(400)
    expect(json_body).to eq('error' => { 'message' => 'Invalid currency: xyz' })
  end

  it 'raises on an invalid JSON body before calling Stripe' do
    expect(Stripe::PaymentIntent).not_to receive(:create)

    expect do
      post '/create-payment-intent', 'not json', 'CONTENT_TYPE' => 'application/json'
    end.to raise_error(JSON::ParserError)
  end

  it 'raises NoMethodError from the generic rescue for non-Stripe exceptions' do
    # The generic rescue reads `e.error.message`, which only Stripe errors
    # expose; this documents the sample's behaviour rather than fixing it.
    expect(Stripe::PaymentIntent).to receive(:create).and_raise(RuntimeError, 'boom')

    expect { post_intent(paymentMethodType: 'card', currency: 'usd') }
      .to raise_error(NoMethodError, /error/)
  end
end
