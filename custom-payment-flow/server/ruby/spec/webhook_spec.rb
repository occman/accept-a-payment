RSpec.describe 'POST /webhook' do
  def event_payload(type)
    { id: 'evt_123', type: type, data: { object: { id: 'pi_123', object: 'payment_intent' } } }
  end

  def post_webhook(payload, signature: nil)
    env = { 'CONTENT_TYPE' => 'application/json' }
    env['HTTP_STRIPE_SIGNATURE'] = signature if signature
    post '/webhook', payload.to_json, env
  end

  it 'verifies the signature against the raw body and handles payment_intent.succeeded' do
    payload = event_payload('payment_intent.succeeded')
    expect(Stripe::Webhook).to receive(:construct_event)
      .with(payload.to_json, 't=1,v1=sig', 'whsec_test')
      .and_return(Stripe::Event.construct_from(payload))

    expect { post_webhook(payload, signature: 't=1,v1=sig') }
      .to output(/Payment received!/).to_stdout

    expect(last_response.status).to eq(200)
    expect(last_response.content_type).to include('application/json')
    expect(json_body).to eq('status' => 'success')
  end

  it 'parses the event without verification when no webhook secret is configured' do
    payload = event_payload('payment_intent.payment_failed')
    expect(Stripe::Webhook).not_to receive(:construct_event)

    original = ENV['STRIPE_WEBHOOK_SECRET']
    ENV['STRIPE_WEBHOOK_SECRET'] = ''
    begin
      expect { post_webhook(payload) }.to output(/Payment failed\./).to_stdout
    ensure
      ENV['STRIPE_WEBHOOK_SECRET'] = original
    end

    expect(last_response.status).to eq(200)
    expect(json_body).to eq('status' => 'success')
  end

  it 'acknowledges unhandled event types without logging' do
    payload = event_payload('charge.refunded')
    allow(Stripe::Webhook).to receive(:construct_event)
      .and_return(Stripe::Event.construct_from(payload))

    expect { post_webhook(payload, signature: 't=1,v1=sig') }.not_to output.to_stdout

    expect(last_response.status).to eq(200)
    expect(json_body).to eq('status' => 'success')
  end
end
