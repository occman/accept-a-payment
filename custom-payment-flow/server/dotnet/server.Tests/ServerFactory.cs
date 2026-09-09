using Microsoft.AspNetCore.Hosting;
using Microsoft.AspNetCore.Mvc.Testing;
using Microsoft.AspNetCore.TestHost;
using Microsoft.Extensions.Configuration;
using Microsoft.Extensions.DependencyInjection;
using Moq;
using Stripe;
using Stripe.Tax;

namespace Server.Tests;

/// <summary>
/// Boots Program.cs in-memory with fake Stripe keys, a temporary STATIC_DIR and
/// the Stripe.net services replaced by Moq mocks, so no request ever leaves the process.
/// </summary>
public class ServerFactory : WebApplicationFactory<Program>
{
    public const string PublishableKey = "pk_test_123";
    public const string SecretKey = "sk_test_123";
    public const string WebhookSecret = "whsec_test_123";

    public static readonly string StaticDir = Path.Combine(
        Path.GetTempPath(), "accept-a-payment-dotnet-tests", Guid.NewGuid().ToString("N"));

    static ServerFactory()
    {
        Directory.CreateDirectory(StaticDir);
        System.IO.File.WriteAllText(Path.Combine(StaticDir, "index.html"), "<html><body>index</body></html>");

        Environment.SetEnvironmentVariable("STRIPE_PUBLISHABLE_KEY", PublishableKey);
        Environment.SetEnvironmentVariable("STRIPE_SECRET_KEY", SecretKey);
        Environment.SetEnvironmentVariable("STRIPE_WEBHOOK_SECRET", WebhookSecret);
        Environment.SetEnvironmentVariable("STATIC_DIR", StaticDir);
    }

    public Mock<PaymentIntentService> PaymentIntents { get; } = new(MockBehavior.Strict);
    public Mock<CalculationService> Calculations { get; } = new(MockBehavior.Strict);

    protected virtual bool CalculateTax => false;

    protected override void ConfigureWebHost(IWebHostBuilder builder)
    {
        builder.ConfigureAppConfiguration(config =>
            config.AddInMemoryCollection(new Dictionary<string, string?>
            {
                ["Stripe:CalculateTax"] = CalculateTax ? "true" : "false",
            }));

        builder.ConfigureTestServices(services =>
        {
            services.AddSingleton(PaymentIntents.Object);
            services.AddSingleton(Calculations.Object);
        });
    }

    public HttpClient CreateClientWithoutRedirects() =>
        CreateClient(new WebApplicationFactoryClientOptions { AllowAutoRedirect = false });
}

public class TaxEnabledServerFactory : ServerFactory
{
    protected override bool CalculateTax => true;
}
