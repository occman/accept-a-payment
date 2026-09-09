using System.Net;
using System.Net.Http.Json;
using System.Text;
using System.Text.Json;
using Moq;
using Stripe;
using Stripe.Tax;

namespace Server.Tests;

public class CreatePaymentIntentTests : IClassFixture<ServerFactory>
{
    private readonly ServerFactory _factory;
    private readonly HttpClient _client;

    public CreatePaymentIntentTests(ServerFactory factory)
    {
        _factory = factory;
        _factory.PaymentIntents.Reset();
        _factory.Calculations.Reset();
        _client = factory.CreateClient();
    }

    private PaymentIntentCreateOptions? _captured;

    private void StripeReturns(string clientSecret)
    {
        _factory.PaymentIntents
            .Setup(s => s.CreateAsync(It.IsAny<PaymentIntentCreateOptions>(), null, It.IsAny<CancellationToken>()))
            .Callback<PaymentIntentCreateOptions, RequestOptions, CancellationToken>((o, _, _) => _captured = o)
            .ReturnsAsync(new PaymentIntent { Id = "pi_123", ClientSecret = clientSecret });
    }

    private Task<HttpResponseMessage> PostAsync(object body) =>
        _client.PostAsJsonAsync("/create-payment-intent", body);

    [Fact]
    public async Task CardHappyPathReturnsClientSecret()
    {
        StripeReturns("pi_123_secret_456");

        var response = await PostAsync(new { paymentMethodType = "card", currency = "usd" });

        Assert.Equal(HttpStatusCode.OK, response.StatusCode);
        var json = await response.Content.ReadFromJsonAsync<JsonElement>();
        Assert.Equal("pi_123_secret_456", json.GetProperty("clientSecret").GetString());

        Assert.NotNull(_captured);
        Assert.Equal(5999, _captured.Amount);
        Assert.Equal("usd", _captured.Currency);
        Assert.Equal(new[] { "card" }, _captured.PaymentMethodTypes);
        Assert.Null(_captured.PaymentMethodOptions);
        Assert.Null(_captured.Metadata);
        _factory.Calculations.VerifyNoOtherCalls();
    }

    [Fact]
    public async Task LinkAlsoEnablesCard()
    {
        StripeReturns("pi_link_secret");

        var response = await PostAsync(new { paymentMethodType = "link", currency = "usd" });

        Assert.Equal(HttpStatusCode.OK, response.StatusCode);
        Assert.Equal(new[] { "link", "card" }, _captured!.PaymentMethodTypes);
        Assert.Null(_captured.PaymentMethodOptions);
    }

    [Fact]
    public async Task AcssDebitAddsMandateOptions()
    {
        StripeReturns("pi_acss_secret");

        var response = await PostAsync(new { paymentMethodType = "acss_debit", currency = "cad" });

        Assert.Equal(HttpStatusCode.OK, response.StatusCode);
        Assert.Equal(new[] { "acss_debit" }, _captured!.PaymentMethodTypes);
        Assert.Equal("cad", _captured.Currency);
        var mandate = _captured.PaymentMethodOptions?.AcssDebit?.MandateOptions;
        Assert.NotNull(mandate);
        Assert.Equal("sporadic", mandate.PaymentSchedule);
        Assert.Equal("personal", mandate.TransactionType);
    }

    [Fact]
    public async Task MissingCurrencyIsForwardedToStripe()
    {
        StripeReturns("pi_nocurrency_secret");

        var response = await PostAsync(new { paymentMethodType = "card" });

        Assert.Equal(HttpStatusCode.OK, response.StatusCode);
        Assert.Null(_captured!.Currency);
        Assert.Equal(new[] { "card" }, _captured.PaymentMethodTypes);
    }

    [Fact]
    public async Task StripeErrorReturns400WithMessage()
    {
        _factory.PaymentIntents
            .Setup(s => s.CreateAsync(It.IsAny<PaymentIntentCreateOptions>(), null, It.IsAny<CancellationToken>()))
            .ThrowsAsync(new StripeException(
                HttpStatusCode.BadRequest,
                new StripeError { Message = "Your card was declined." },
                "Your card was declined."));

        var response = await PostAsync(new { paymentMethodType = "card", currency = "usd" });

        Assert.Equal(HttpStatusCode.BadRequest, response.StatusCode);
        var json = await response.Content.ReadFromJsonAsync<JsonElement>();
        Assert.Equal("Your card was declined.", json.GetProperty("error").GetProperty("message").GetString());
    }

    [Fact]
    public async Task EmptyBodyFailsBeforeCallingStripe()
    {
        var response = await _client.PostAsync("/create-payment-intent",
            new StringContent("", Encoding.UTF8, "application/json"));

        // The request model binds to null and the handler dereferences it, so ASP.NET Core
        // answers 500 (developer exception page). Stripe must not be reached either way.
        Assert.Equal(HttpStatusCode.InternalServerError, response.StatusCode);
        _factory.PaymentIntents.VerifyNoOtherCalls();
    }

    [Fact]
    public async Task MalformedJsonIsRejectedBeforeCallingStripe()
    {
        var response = await _client.PostAsync("/create-payment-intent",
            new StringContent("{not json", Encoding.UTF8, "application/json"));

        Assert.Equal(HttpStatusCode.BadRequest, response.StatusCode);
        _factory.PaymentIntents.VerifyNoOtherCalls();
    }
}

public class CreatePaymentIntentWithTaxTests : IClassFixture<TaxEnabledServerFactory>
{
    private readonly TaxEnabledServerFactory _factory;
    private readonly HttpClient _client;

    public CreatePaymentIntentWithTaxTests(TaxEnabledServerFactory factory)
    {
        _factory = factory;
        _factory.PaymentIntents.Reset();
        _factory.Calculations.Reset();
        _client = factory.CreateClient();
    }

    [Fact]
    public async Task CalculateTaxUsesTaxTotalAndRecordsCalculationId()
    {
        CalculationCreateOptions? taxOptions = null;
        _factory.Calculations
            .Setup(s => s.Create(It.IsAny<CalculationCreateOptions>(), null))
            .Callback<CalculationCreateOptions, RequestOptions>((o, _) => taxOptions = o)
            .Returns(new Calculation { Id = "taxcalc_123", AmountTotal = 6899 });

        PaymentIntentCreateOptions? captured = null;
        _factory.PaymentIntents
            .Setup(s => s.CreateAsync(It.IsAny<PaymentIntentCreateOptions>(), null, It.IsAny<CancellationToken>()))
            .Callback<PaymentIntentCreateOptions, RequestOptions, CancellationToken>((o, _, _) => captured = o)
            .ReturnsAsync(new PaymentIntent { Id = "pi_tax", ClientSecret = "pi_tax_secret" });

        var response = await _client.PostAsJsonAsync("/create-payment-intent",
            new { paymentMethodType = "card", currency = "usd" });

        Assert.Equal(HttpStatusCode.OK, response.StatusCode);
        var json = await response.Content.ReadFromJsonAsync<JsonElement>();
        Assert.Equal("pi_tax_secret", json.GetProperty("clientSecret").GetString());

        Assert.NotNull(taxOptions);
        Assert.Equal("usd", taxOptions.Currency);
        Assert.Equal("shipping", taxOptions.CustomerDetails.AddressSource);
        Assert.Equal("Seattle", taxOptions.CustomerDetails.Address.City);
        Assert.Equal("US", taxOptions.CustomerDetails.Address.Country);
        var lineItem = Assert.Single(taxOptions.LineItems);
        Assert.Equal(5999, lineItem.Amount);
        Assert.Equal("txcd_30011000", lineItem.TaxCode);
        Assert.Equal(300, taxOptions.ShippingCost.Amount);

        Assert.NotNull(captured);
        Assert.Equal(6899, captured.Amount);
        Assert.Equal("usd", captured.Currency);
        Assert.Equal(new[] { "card" }, captured.PaymentMethodTypes);
        Assert.Equal("taxcalc_123", captured.Metadata["tax_calculation"]);
    }
}
