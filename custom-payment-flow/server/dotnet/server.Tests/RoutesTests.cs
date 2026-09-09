using System.Net;
using System.Net.Http.Json;
using System.Text.Json;
using Moq;
using Stripe;

namespace Server.Tests;

public class RoutesTests : IClassFixture<ServerFactory>
{
    private readonly ServerFactory _factory;
    private readonly HttpClient _client;

    public RoutesTests(ServerFactory factory)
    {
        _factory = factory;
        _factory.PaymentIntents.Reset();
        _client = factory.CreateClientWithoutRedirects();
    }

    [Fact]
    public async Task ConfigReturnsPublishableKeyAsJson()
    {
        var response = await _client.GetAsync("/config");

        Assert.Equal(HttpStatusCode.OK, response.StatusCode);
        Assert.Equal("application/json", response.Content.Headers.ContentType?.MediaType);
        var json = await response.Content.ReadFromJsonAsync<JsonElement>();
        Assert.Equal(ServerFactory.PublishableKey, json.GetProperty("publishableKey").GetString());
    }

    [Fact]
    public async Task RootRedirectsToIndexHtml()
    {
        var response = await _client.GetAsync("/");

        Assert.Equal(HttpStatusCode.Found, response.StatusCode);
        Assert.Equal("index.html", response.Headers.Location?.OriginalString);
    }

    [Fact]
    public async Task SuccessRedirectsToStaticPage()
    {
        var response = await _client.GetAsync("/success");

        Assert.Equal(HttpStatusCode.Found, response.StatusCode);
        Assert.Equal("success.html", response.Headers.Location?.OriginalString);
    }

    [Fact]
    public async Task StaticFilesAreServedFromStaticDir()
    {
        var response = await _client.GetAsync("/index.html");

        Assert.Equal(HttpStatusCode.OK, response.StatusCode);
        Assert.Equal("text/html", response.Content.Headers.ContentType?.MediaType);
        Assert.Contains("index", await response.Content.ReadAsStringAsync());
    }

    [Fact]
    public async Task PaymentNextRetrievesIntentAndRedirectsToSuccess()
    {
        _factory.PaymentIntents
            .Setup(s => s.Get("pi_123", null, null))
            .Returns(new PaymentIntent { Id = "pi_123", ClientSecret = "pi_123_secret_456" });

        var response = await _client.GetAsync("/payment/next?payment_intent=pi_123");

        Assert.Equal(HttpStatusCode.Found, response.StatusCode);
        Assert.StartsWith("/success?payment_intent_client_secret=", response.Headers.Location?.OriginalString);
        _factory.PaymentIntents.Verify(s => s.Get("pi_123", null, null), Times.Once);
    }
}
