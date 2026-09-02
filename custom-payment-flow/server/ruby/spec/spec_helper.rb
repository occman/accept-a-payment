require 'simplecov'

SimpleCov.start do
  enable_coverage :branch
  add_filter '/spec/'
  add_filter '/vendor/'
  # Interactive first-run setup helper; not part of the HTTP surface under test.
  add_filter 'config_helper.rb'
  minimum_coverage line: 80, branch: 75
end

ENV['APP_ENV'] = 'test'
ENV['STATIC_DIR'] = '../../client/html'
ENV['STRIPE_PUBLISHABLE_KEY'] = 'pk_test_fake'
ENV['STRIPE_SECRET_KEY'] = 'sk_test_fake'
ENV['STRIPE_WEBHOOK_SECRET'] = 'whsec_test'

require 'rack/test'
require 'json'

# server.rb runs ConfigHelper.check_env! at load time, which shells out to the
# Stripe CLI and makes a live API request. Neutralise it before the server is
# loaded so the suite is fully offline.
require_relative '../config_helper'
ConfigHelper.define_singleton_method(:check_env!) {}

require_relative '../server'

module AppHelpers
  include Rack::Test::Methods

  def app
    Sinatra::Application
  end

  def json_body
    JSON.parse(last_response.body)
  end

  def stripe_error(message, param = nil)
    Stripe::InvalidRequestError.new(
      message, param, http_status: 400, json_body: { error: { message: message } }
    )
  end
end

RSpec.configure do |config|
  config.include AppHelpers

  config.expect_with :rspec do |expectations|
    expectations.include_chain_clauses_in_custom_matcher_descriptions = true
  end

  config.mock_with :rspec do |mocks|
    mocks.verify_partial_doubles = true
  end

  config.shared_context_metadata_behavior = :apply_to_host_groups
  config.disable_monkey_patching!
  config.order = :random
  Kernel.srand config.seed
end
