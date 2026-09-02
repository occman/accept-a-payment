RSpec.describe 'static pages and redirects' do
  it 'GET / serves index.html from STATIC_DIR' do
    get '/'

    expect(last_response.status).to eq(200)
    expect(last_response.content_type).to include('text/html')
    expect(last_response.body).to include('<html')
  end

  it 'GET /success serves success.html from STATIC_DIR' do
    get '/success'

    expect(last_response.status).to eq(200)
    expect(last_response.content_type).to include('text/html')
    expect(last_response.body).to include('<html')
  end

  it 'GET /payment/next retrieves the PaymentIntent and redirects to /success' do
    intent = double('Stripe::PaymentIntent', client_secret: 'pi_123_secret_abc')
    expect(Stripe::PaymentIntent).to receive(:retrieve).with('pi_123').and_return(intent)

    get '/payment/next', payment_intent: 'pi_123'

    expect(last_response.status).to eq(302)
    expect(last_response.location).to end_with('/success?payment_intent_client_secret=pi_123_secret_abc')
  end
end
