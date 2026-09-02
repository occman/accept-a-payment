require 'json'
require 'openssl'

RSpec.describe 'custom-payment-flow ruby server' do
  def app
    Sinatra::Application
  end

  def json_body
    JSON.parse(last_response.body)
  end

  def post_json(path, payload)
    post path, payload.to_json, 'CONTENT_TYPE' => 'application/json'
  end

  describe 'GET /config' do
    it 'returns the publishable key as JSON' do
      get '/config'

      expect(last_response.status).to eq(200)
      expect(last_response.content_type).to include('application/json')
      expect(json_body).to eq('publishableKey' => ENV['STRIPE_PUBLISHABLE_KEY'])
    end
  end

  describe 'GET /' do
    it 'serves the static index.html' do
      get '/'

      expect(last_response.status).to eq(200)
      expect(last_response.content_type).to include('text/html')
      expect(last_response.body).to include('<html')
    end
  end

  describe 'GET /success' do
    it 'serves the static success.html' do
      get '/success'

      expect(last_response.status).to eq(200)
      expect(last_response.content_type).to include('text/html')
      expect(last_response.body).to include('<html')
    end
  end

  describe 'POST /create-payment-intent' do
    let(:payment_intent) { double('Stripe::PaymentIntent', client_secret: 'pi_123_secret_456') }

    context 'happy path' do
      it 'creates a card PaymentIntent and returns the client secret' do
        expect(Stripe::PaymentIntent).to receive(:create).with({
          payment_method_types: ['card'],
          amount: 5999,
          currency: 'usd',
        }).and_return(payment_intent)

        post_json '/create-payment-intent', paymentMethodType: 'card', currency: 'usd'

        expect(last_response.status).to eq(200)
        expect(last_response.content_type).to include('application/json')
        expect(json_body).to eq('clientSecret' => 'pi_123_secret_456')
      end

      it 'requests both link and card when paymentMethodType is link' do
        expect(Stripe::PaymentIntent).to receive(:create).with(
          hash_including(payment_method_types: %w[link card], currency: 'eur')
        ).and_return(payment_intent)

        post_json '/create-payment-intent', paymentMethodType: 'link', currency: 'eur'

        expect(last_response.status).to eq(200)
        expect(json_body['clientSecret']).to eq('pi_123_secret_456')
      end

      it 'adds ACSS mandate options when paymentMethodType is acss_debit' do
        expect(Stripe::PaymentIntent).to receive(:create).with({
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
        }).and_return(payment_intent)

        post_json '/create-payment-intent', paymentMethodType: 'acss_debit', currency: 'cad'

        expect(last_response.status).to eq(200)
        expect(json_body['clientSecret']).to eq('pi_123_secret_456')
      end

      it 'does not add payment_method_options for non-ACSS payment methods' do
        expect(Stripe::PaymentIntent).to receive(:create) do |params|
          expect(params).not_to have_key(:payment_method_options)
          payment_intent
        end

        post_json '/create-payment-intent', paymentMethodType: 'ideal', currency: 'eur'

        expect(last_response.status).to eq(200)
      end
    end

    context 'invalid or missing params' do
      it 'forwards missing params to Stripe and surfaces the resulting 400 error' do
        stripe_error = Stripe::InvalidRequestError.new(
          'Missing required param: currency.', 'currency', http_status: 400,
          json_body: { error: { message: 'Missing required param: currency.' } }
        )
        expect(Stripe::PaymentIntent).to receive(:create).with(
          hash_including(payment_method_types: [nil], currency: nil)
        ).and_raise(stripe_error)

        post_json '/create-payment-intent', {}

        expect(last_response.status).to eq(400)
        expect(last_response.content_type).to include('application/json')
        expect(json_body).to eq('error' => { 'message' => 'Missing required param: currency.' })
      end

      it 'returns 500 when the request body is not valid JSON' do
        expect(Stripe::PaymentIntent).not_to receive(:create)

        post '/create-payment-intent', 'not json', 'CONTENT_TYPE' => 'application/json'

        expect(last_response.status).to eq(500)
      end
    end

    context 'Stripe API errors' do
      it 'returns 400 with the Stripe error message on Stripe::StripeError' do
        stripe_error = Stripe::CardError.new(
          'Your card was declined.', 'card', code: 'card_declined', http_status: 402,
          json_body: { error: { message: 'Your card was declined.' } }
        )
        allow(Stripe::PaymentIntent).to receive(:create).and_raise(stripe_error)

        post_json '/create-payment-intent', paymentMethodType: 'card', currency: 'usd'

        expect(last_response.status).to eq(400)
        expect(last_response.content_type).to include('application/json')
        expect(json_body).to eq('error' => { 'message' => 'Your card was declined.' })
      end

      it 'returns 500 on an unexpected non-Stripe error' do
        allow(Stripe::PaymentIntent).to receive(:create).and_raise(StandardError, 'boom')

        post_json '/create-payment-intent', paymentMethodType: 'card', currency: 'usd'

        expect(last_response.status).to eq(500)
      end
    end
  end

  describe 'GET /payment/next' do
    it 'retrieves the PaymentIntent and redirects to /success with its client secret' do
      intent = double('Stripe::PaymentIntent', client_secret: 'pi_abc_secret_xyz')
      expect(Stripe::PaymentIntent).to receive(:retrieve).with('pi_abc').and_return(intent)

      get '/payment/next', payment_intent: 'pi_abc'

      expect(last_response.status).to eq(302)
      expect(last_response.location).to end_with('/success?payment_intent_client_secret=pi_abc_secret_xyz')
    end
  end

  describe 'POST /webhook' do
    let(:webhook_secret) { ENV['STRIPE_WEBHOOK_SECRET'] }

    def signed_header(payload, secret, timestamp: Time.now.to_i)
      signature = OpenSSL::HMAC.hexdigest('SHA256', secret, "#{timestamp}.#{payload}")
      "t=#{timestamp},v1=#{signature}"
    end

    def event_payload(type)
      {
        id: 'evt_123',
        object: 'event',
        type: type,
        data: { object: { id: 'pi_123', object: 'payment_intent', amount: 5999 } },
      }.to_json
    end

    it 'verifies a valid signature and acknowledges a payment_intent.succeeded event' do
      payload = event_payload('payment_intent.succeeded')
      expect(Stripe::Webhook).to receive(:construct_event).and_call_original

      post '/webhook', payload, 'CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => signed_header(payload, webhook_secret)

      expect(last_response.status).to eq(200)
      expect(last_response.content_type).to include('application/json')
      expect(json_body).to eq('status' => 'success')
    end

    it 'verifies a valid signature and acknowledges a payment_intent.payment_failed event' do
      payload = event_payload('payment_intent.payment_failed')

      post '/webhook', payload, 'CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => signed_header(payload, webhook_secret)

      expect(last_response.status).to eq(200)
      expect(json_body).to eq('status' => 'success')
    end

    it 'rejects an event with an invalid signature' do
      payload = event_payload('payment_intent.succeeded')

      post '/webhook', payload, 'CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => signed_header(payload, 'whsec_wrong')

      expect(last_response.status).to eq(400)
    end
  end
end
