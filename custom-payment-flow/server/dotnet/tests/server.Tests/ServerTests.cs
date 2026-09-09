using System.Net;
using System.Net.Http.Json;
using System.Text;
using System.Text.Json;
using Stripe;
using Xunit;

[assembly: CollectionBehavior(DisableTestParallelization = true)]

namespace server.Tests;

public class ServerTests : IDisposable
{
    private readonly ServerFixture server = new();

    public void Dispose() => server.Dispose();

    private const string PaymentIntentJson =
        """{"id":"pi_123","object":"payment_intent","client_secret":"pi_123_secret_abc","amount":5999,"currency":"usd"}""";

    private static StringContent Json(object body) =>
        new(JsonSerializer.Serialize(body), Encoding.UTF8, "application/json");

    private static async Task<JsonElement> ReadJson(HttpResponseMessage response) =>
        JsonDocument.Parse(await response.Content.ReadAsStringAsync()).RootElement;

    [Fact]
    public async Task Root_RedirectsToIndexHtml()
    {
        var response = await server.Client.GetAsync("/");

        Assert.Equal(HttpStatusCode.Found, response.StatusCode);
        Assert.Equal("index.html", response.Headers.Location?.ToString());
    }

    [Fact]
    public async Task Success_RedirectsToSuccessHtml()
    {
        var response = await server.Client.GetAsync("/success");

        Assert.Equal(HttpStatusCode.Found, response.StatusCode);
        Assert.Equal("success.html", response.Headers.Location?.ToString());
    }

    [Fact]
    public async Task StaticFiles_AreServedFromStaticDir()
    {
        var response = await server.Client.GetAsync("/index.html");

        Assert.Equal(HttpStatusCode.OK, response.StatusCode);
        Assert.Equal("<html>index</html>", await response.Content.ReadAsStringAsync());
    }

    [Fact]
    public async Task Config_ReturnsPublishableKey()
    {
        var response = await server.Client.GetAsync("/config");

        Assert.Equal(HttpStatusCode.OK, response.StatusCode);
        var json = await ReadJson(response);
        Assert.Equal(ServerFixture.PublishableKey, json.GetProperty("publishableKey").GetString());
        Assert.Single(json.EnumerateObject());
    }

    [Fact]
    public async Task CreatePaymentIntent_Card_ReturnsClientSecret()
    {
        server.StubStripe("/v1/payment_intents", HttpStatusCode.OK, PaymentIntentJson);

        var response = await server.Client.PostAsync("/create-payment-intent",
            Json(new { paymentMethodType = "card", currency = "usd" }));

        Assert.Equal(HttpStatusCode.OK, response.StatusCode);
        var json = await ReadJson(response);
        Assert.Equal("pi_123_secret_abc", json.GetProperty("clientSecret").GetString());

        var request = Assert.Single(server.Requests);
        Assert.Equal(HttpMethod.Post, request.Method);
        Assert.Equal("Bearer", request.AuthorizationHeader.Scheme);
        Assert.Equal(ServerFixture.SecretKey, request.AuthorizationHeader.Parameter);
        var body = await ServerFixture.FormBody(request);
        Assert.Contains("amount=5999", body);
        Assert.Contains("currency=usd", body);
        Assert.Contains("payment_method_types[0]=card", body);
        Assert.DoesNotContain("payment_method_types[1]", body);
        Assert.DoesNotContain("payment_method_options", body);
        Assert.DoesNotContain("metadata", body);
    }

    [Theory]
    [InlineData("sepa_debit", "eur")]
    [InlineData("klarna", "eur")]
    [InlineData("konbini", "jpy")]
    public async Task CreatePaymentIntent_PassesThroughPaymentMethodTypeAndCurrency(string type, string currency)
    {
        server.StubStripe("/v1/payment_intents", HttpStatusCode.OK, PaymentIntentJson);

        var response = await server.Client.PostAsync("/create-payment-intent",
            Json(new { paymentMethodType = type, currency }));

        Assert.Equal(HttpStatusCode.OK, response.StatusCode);
        var body = await ServerFixture.FormBody(Assert.Single(server.Requests));
        Assert.Contains($"payment_method_types[0]={type}", body);
        Assert.Contains($"currency={currency}", body);
    }

    [Fact]
    public async Task CreatePaymentIntent_Link_AlsoRequestsCard()
    {
        server.StubStripe("/v1/payment_intents", HttpStatusCode.OK, PaymentIntentJson);

        var response = await server.Client.PostAsync("/create-payment-intent",
            Json(new { paymentMethodType = "link", currency = "usd" }));

        Assert.Equal(HttpStatusCode.OK, response.StatusCode);
        var body = await ServerFixture.FormBody(Assert.Single(server.Requests));
        Assert.Contains("payment_method_types[0]=link", body);
        Assert.Contains("payment_method_types[1]=card", body);
    }

    [Fact]
    public async Task CreatePaymentIntent_AcssDebit_AddsMandateOptions()
    {
        server.StubStripe("/v1/payment_intents", HttpStatusCode.OK, PaymentIntentJson);

        var response = await server.Client.PostAsync("/create-payment-intent",
            Json(new { paymentMethodType = "acss_debit", currency = "cad" }));

        Assert.Equal(HttpStatusCode.OK, response.StatusCode);
        var body = await ServerFixture.FormBody(Assert.Single(server.Requests));
        Assert.Contains("payment_method_types[0]=acss_debit", body);
        Assert.Contains("payment_method_options[acss_debit][mandate_options][payment_schedule]=sporadic", body);
        Assert.Contains("payment_method_options[acss_debit][mandate_options][transaction_type]=personal", body);
    }

    [Fact]
    public async Task CreatePaymentIntent_StripeError_Returns400WithMessage()
    {
        server.StubStripe("/v1/payment_intents", HttpStatusCode.BadRequest,
            """{"error":{"type":"invalid_request_error","message":"Invalid currency: xyz"}}""");

        var response = await server.Client.PostAsync("/create-payment-intent",
            Json(new { paymentMethodType = "card", currency = "xyz" }));

        Assert.Equal(HttpStatusCode.BadRequest, response.StatusCode);
        var json = await ReadJson(response);
        Assert.Equal("Invalid currency: xyz", json.GetProperty("error").GetProperty("message").GetString());
    }

    [Fact]
    public async Task CreatePaymentIntent_MalformedJson_Returns400()
    {
        var response = await server.Client.PostAsync("/create-payment-intent",
            new StringContent("{not json", Encoding.UTF8, "application/json"));

        Assert.Equal(HttpStatusCode.BadRequest, response.StatusCode);
        Assert.Empty(server.Requests);
    }

    [Fact]
    public async Task PaymentNext_FetchesIntentAndRedirectsToSuccess()
    {
        server.StubStripe("/v1/payment_intents/pi_123", HttpStatusCode.OK, PaymentIntentJson);

        var response = await server.Client.GetAsync("/payment/next?payment_intent=pi_123");

        Assert.Equal(HttpStatusCode.Found, response.StatusCode);
        Assert.StartsWith("/success?payment_intent_client_secret=", response.Headers.Location?.ToString());
        var request = Assert.Single(server.Requests);
        Assert.Equal(HttpMethod.Get, request.Method);
    }

    private static string EventJson(string type, string id = "evt_123") =>
        $$"""
        {
          "id": "{{id}}",
          "object": "event",
          "api_version": "{{StripeConfiguration.ApiVersion}}",
          "type": "{{type}}",
          "data": { "object": {{PaymentIntentJson}} }
        }
        """;

    private static string SignatureHeader(string payload, string secret, long? timestamp = null)
    {
        var ts = (timestamp ?? DateTimeOffset.UtcNow.ToUnixTimeSeconds()).ToString();
        return $"t={ts},v1={EventUtility.ComputeSignature(secret, ts, payload)}";
    }

    private Task<HttpResponseMessage> PostWebhook(string payload, string signature)
    {
        var content = new StringContent(payload, Encoding.UTF8, "application/json");
        var request = new HttpRequestMessage(HttpMethod.Post, "/webhook") { Content = content };
        if (signature != null)
        {
            request.Headers.Add("Stripe-Signature", signature);
        }
        return server.Client.SendAsync(request);
    }

    [Fact]
    public async Task Webhook_ValidSignature_PaymentIntentSucceeded_Returns200()
    {
        var payload = EventJson("payment_intent.succeeded");

        var response = await PostWebhook(payload, SignatureHeader(payload, ServerFixture.WebhookSecret));

        Assert.Equal(HttpStatusCode.OK, response.StatusCode);
    }

    [Fact]
    public async Task Webhook_ValidSignature_OtherEvent_Returns200()
    {
        var payload = EventJson("payment_intent.payment_failed");

        var response = await PostWebhook(payload, SignatureHeader(payload, ServerFixture.WebhookSecret));

        Assert.Equal(HttpStatusCode.OK, response.StatusCode);
    }

    [Fact]
    public async Task Webhook_WrongSecret_Returns400()
    {
        var payload = EventJson("payment_intent.succeeded");

        var response = await PostWebhook(payload, SignatureHeader(payload, "whsec_wrong"));

        Assert.Equal(HttpStatusCode.BadRequest, response.StatusCode);
    }

    [Fact]
    public async Task Webhook_TamperedPayload_Returns400()
    {
        var signature = SignatureHeader(EventJson("payment_intent.succeeded"), ServerFixture.WebhookSecret);

        var response = await PostWebhook(EventJson("payment_intent.succeeded", id: "evt_tampered"), signature);

        Assert.Equal(HttpStatusCode.BadRequest, response.StatusCode);
    }

    [Fact]
    public async Task Webhook_ExpiredTimestamp_Returns400()
    {
        var payload = EventJson("payment_intent.succeeded");
        var stale = DateTimeOffset.UtcNow.AddHours(-1).ToUnixTimeSeconds();

        var response = await PostWebhook(payload, SignatureHeader(payload, ServerFixture.WebhookSecret, stale));

        Assert.Equal(HttpStatusCode.BadRequest, response.StatusCode);
    }

    [Fact]
    public async Task Webhook_MissingSignature_Returns400()
    {
        var response = await PostWebhook(EventJson("payment_intent.succeeded"), signature: null);

        Assert.Equal(HttpStatusCode.BadRequest, response.StatusCode);
    }
}

public class CalculateTaxTests : IDisposable
{
    private readonly ServerFixture server = new(calculateTax: true);

    public void Dispose() => server.Dispose();

    [Fact]
    public async Task CreatePaymentIntent_UsesTaxCalculationTotalAndMetadata()
    {
        server.StubStripe("/v1/tax/calculations", HttpStatusCode.OK,
            """{"id":"taxcalc_123","object":"tax.calculation","amount_total":6599,"currency":"usd"}""");
        server.StubStripe("/v1/payment_intents", HttpStatusCode.OK,
            """{"id":"pi_tax","object":"payment_intent","client_secret":"pi_tax_secret"}""");

        var response = await server.Client.PostAsync("/create-payment-intent",
            new StringContent("""{"paymentMethodType":"card","currency":"usd"}""", Encoding.UTF8, "application/json"));

        Assert.Equal(HttpStatusCode.OK, response.StatusCode);
        Assert.Equal(2, server.Requests.Count);

        var taxBody = await ServerFixture.FormBody(server.Requests[0]);
        Assert.EndsWith("/v1/tax/calculations", server.Requests[0].Uri.AbsolutePath);
        Assert.Contains("currency=usd", taxBody);
        Assert.Contains("line_items[0][amount]=5999", taxBody);
        Assert.Contains("line_items[0][tax_code]=txcd_30011000", taxBody);
        Assert.Contains("shipping_cost[amount]=300", taxBody);

        var intentBody = await ServerFixture.FormBody(server.Requests[1]);
        Assert.Contains("amount=6599", intentBody);
        Assert.Contains("metadata[tax_calculation]=taxcalc_123", intentBody);
    }
}
