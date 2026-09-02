RSpec.describe 'GET /config' do
  it 'returns the publishable key from the environment as JSON' do
    get '/config'

    expect(last_response.status).to eq(200)
    expect(last_response.content_type).to include('application/json')
    expect(json_body).to eq('publishableKey' => 'pk_test_fake')
  end

  it 'returns null when the publishable key is unset' do
    original = ENV.delete('STRIPE_PUBLISHABLE_KEY')
    begin
      get '/config'
    ensure
      ENV['STRIPE_PUBLISHABLE_KEY'] = original
    end

    expect(last_response.status).to eq(200)
    expect(json_body).to eq('publishableKey' => nil)
  end
end
