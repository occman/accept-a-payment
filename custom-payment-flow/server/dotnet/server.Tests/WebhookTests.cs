using System.Net;
using System.Security.Cryptography;
using System.Text;
using Stripe;

namespace Server.Tests;

public class WebhookTests : IClassFixture<ServerFactory>
{
    private readonly HttpClient _client;

    public WebhookTests(ServerFactory factory)
    {
        _client = factory.CreateClient();
    }

    private static string EventJson(string type) =>
        "{" +
        "\"id\":\"evt_123\"," +
        "\"object\":\"event\"," +
        $"\"api_version\":\"{StripeConfiguration.ApiVersion}\"," +
        "\"created\":1700000000," +
        "\"livemode\":false," +
        "\"pending_webhooks\":1," +
        "\"request\":{\"id\":null,\"idempotency_key\":null}," +
        $"\"type\":\"{type}\"," +
        "\"data\":{\"object\":{\"id\":\"pi_123\",\"object\":\"payment_intent\",\"client_secret\":\"pi_123_secret_456\",\"amount\":5999,\"currency\":\"usd\"}}" +
        "}";

    /// <summary>Real Stripe-Signature header: t=&lt;unix&gt;,v1=HMAC-SHA256(secret, "&lt;unix&gt;.&lt;payload&gt;").</summary>
    private static string Sign(string payload, string secret)
    {
        var timestamp = DateTimeOffset.UtcNow.ToUnixTimeSeconds().ToString();
        using var hmac = new HMACSHA256(Encoding.UTF8.GetBytes(secret));
        var signature = Convert.ToHexString(hmac.ComputeHash(Encoding.UTF8.GetBytes($"{timestamp}.{payload}"))).ToLowerInvariant();
        return $"t={timestamp},v1={signature}";
    }

    private Task<HttpResponseMessage> PostWebhookAsync(string payload, string? signature)
    {
        var request = new HttpRequestMessage(HttpMethod.Post, "/webhook")
        {
            Content = new StringContent(payload, Encoding.UTF8, "application/json"),
        };
        if (signature != null)
        {
            request.Headers.Add("Stripe-Signature", signature);
        }
        return _client.SendAsync(request);
    }

    [Fact]
    public async Task ValidSignedSucceededEventReturns200()
    {
        var payload = EventJson("payment_intent.succeeded");

        var response = await PostWebhookAsync(payload, Sign(payload, ServerFactory.WebhookSecret));

        Assert.Equal(HttpStatusCode.OK, response.StatusCode);
    }

    [Fact]
    public async Task ValidSignedOtherEventReturns200()
    {
        var payload = EventJson("payment_intent.payment_failed");

        var response = await PostWebhookAsync(payload, Sign(payload, ServerFactory.WebhookSecret));

        Assert.Equal(HttpStatusCode.OK, response.StatusCode);
    }

    [Fact]
    public async Task SignatureFromWrongSecretReturns400()
    {
        var payload = EventJson("payment_intent.succeeded");

        var response = await PostWebhookAsync(payload, Sign(payload, "whsec_wrong"));

        Assert.Equal(HttpStatusCode.BadRequest, response.StatusCode);
    }
}
