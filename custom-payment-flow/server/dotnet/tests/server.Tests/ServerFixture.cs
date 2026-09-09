using System.Net;
using System.Net.Http.Headers;
using Microsoft.AspNetCore.Mvc.Testing;
using Microsoft.Extensions.Configuration;
using Moq;
using Stripe;

namespace server.Tests;

/// <summary>
/// Boots the app in-process with placeholder Stripe keys and swaps the Stripe.net HTTP
/// transport for a Moq mock so no request ever leaves the process.
/// </summary>
public sealed class ServerFixture : IDisposable
{
    public const string PublishableKey = "pk_test_placeholder";
    public const string SecretKey = "sk_test_placeholder";
    public const string WebhookSecret = "whsec_test_placeholder";

    private readonly WebApplicationFactory<Program> factory;
    private readonly string staticDir;

    public ServerFixture(bool calculateTax = false)
    {
        staticDir = Directory.CreateTempSubdirectory("server-tests-static").FullName;
        System.IO.File.WriteAllText(Path.Combine(staticDir, "index.html"), "<html>index</html>");

        Environment.SetEnvironmentVariable("STRIPE_PUBLISHABLE_KEY", PublishableKey);
        Environment.SetEnvironmentVariable("STRIPE_SECRET_KEY", SecretKey);
        Environment.SetEnvironmentVariable("STRIPE_WEBHOOK_SECRET", WebhookSecret);
        Environment.SetEnvironmentVariable("STATIC_DIR", staticDir);

        factory = new WebApplicationFactory<Program>().WithWebHostBuilder(builder =>
        {
            builder.ConfigureAppConfiguration((_, config) =>
                config.AddInMemoryCollection(new Dictionary<string, string>
                {
                    ["Stripe:CalculateTax"] = calculateTax.ToString(),
                }));
        });

        Client = factory.CreateClient(new WebApplicationFactoryClientOptions { AllowAutoRedirect = false });

        HttpClientMock = new Mock<IHttpClient>(MockBehavior.Strict);
        StripeConfiguration.StripeClient = new StripeClient(SecretKey, httpClient: HttpClientMock.Object);
    }

    public HttpClient Client { get; }

    public Mock<IHttpClient> HttpClientMock { get; }

    public List<StripeRequest> Requests { get; } = new();

    /// <summary>Answers every Stripe request whose path ends with <paramref name="pathSuffix"/>.</summary>
    public void StubStripe(string pathSuffix, HttpStatusCode status, string json)
    {
        HttpClientMock
            .Setup(c => c.MakeRequestAsync(
                It.Is<StripeRequest>(r => r.Uri.AbsolutePath.EndsWith(pathSuffix)),
                It.IsAny<CancellationToken>()))
            .Callback<StripeRequest, CancellationToken>((r, _) => Requests.Add(r))
            .ReturnsAsync(new StripeResponse(status, new HttpResponseMessage().Headers, json));
    }

    public static async Task<string> FormBody(StripeRequest request) =>
        await request.Content.ReadAsStringAsync();

    public void Dispose()
    {
        StripeConfiguration.StripeClient = null;
        factory.Dispose();
        Directory.Delete(staticDir, recursive: true);
    }
}
