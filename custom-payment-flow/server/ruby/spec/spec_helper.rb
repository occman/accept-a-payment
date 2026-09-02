require 'simplecov'
SimpleCov.start do
  enable_coverage :branch
  add_filter '/spec/'
  add_filter '/vendor/'
  add_filter 'config_helper.rb'
  track_files 'server.rb'
end

ENV['APP_ENV'] = 'test'
ENV['RACK_ENV'] = 'test'
ENV['STATIC_DIR'] ||= '../../client/html'
ENV['STRIPE_PUBLISHABLE_KEY'] ||= 'pk_test_placeholder'
ENV['STRIPE_SECRET_KEY'] ||= 'sk_test_placeholder'
ENV['STRIPE_WEBHOOK_SECRET'] ||= 'whsec_test_placeholder'

require 'rack/test'
require 'rspec'

# server.rb requires './config_helper.rb' relative to the working directory.
Dir.chdir(File.expand_path('..', __dir__))

# ConfigHelper.check_env! is a startup helper that reads .env, may prompt on
# STDIN, and makes a live Stripe API call. Stub it out so the app can be loaded
# offline without credentials.
require './config_helper.rb'
ConfigHelper.define_singleton_method(:check_env!) { nil }

require './server.rb'

Sinatra::Application.set :raise_errors, false
Sinatra::Application.set :show_exceptions, false
Sinatra::Application.set :logging, false

RSpec.configure do |config|
  config.include Rack::Test::Methods

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

  # Silence the app's `puts` logging without affecting RSpec's own reporter,
  # which holds a reference to the original STDOUT.
  config.around do |example|
    original_stdout = $stdout
    $stdout = StringIO.new
    begin
      example.run
    ensure
      $stdout = original_stdout
    end
  end
end
