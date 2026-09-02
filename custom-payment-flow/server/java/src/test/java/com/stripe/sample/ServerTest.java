package com.stripe.sample;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertNull;
import static org.junit.jupiter.api.Assertions.assertThrows;
import static org.junit.jupiter.api.Assertions.assertTrue;
import static org.mockito.ArgumentMatchers.any;
import static org.mockito.Mockito.mock;
import static org.mockito.Mockito.mockStatic;
import static org.mockito.Mockito.never;
import static org.mockito.Mockito.verify;
import static org.mockito.Mockito.when;

import java.io.BufferedReader;
import java.io.InputStreamReader;
import java.net.HttpURLConnection;
import java.net.URL;
import java.util.Arrays;
import java.util.Collections;

import com.google.gson.Gson;
import com.google.gson.JsonObject;
import com.google.gson.JsonParser;

import com.stripe.Stripe;
import com.stripe.exception.InvalidRequestException;
import com.stripe.model.PaymentIntent;
import com.stripe.net.Webhook;
import com.stripe.param.PaymentIntentCreateParams;

import io.github.cdimascio.dotenv.Dotenv;

import org.junit.jupiter.api.AfterEach;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Nested;
import org.junit.jupiter.api.Test;
import org.mockito.ArgumentCaptor;
import org.mockito.MockedStatic;

import spark.Request;
import spark.Response;
import spark.Spark;

class ServerTest {
    private static final Gson gson = new Gson();
    private static final String WEBHOOK_SECRET = "whsec_test_secret";

    private Dotenv dotenv;
    private Request request;
    private Response response;

    @BeforeEach
    void setUp() {
        dotenv = mock(Dotenv.class);
        when(dotenv.get("STRIPE_PUBLISHABLE_KEY")).thenReturn("pk_test_123");
        when(dotenv.get("STRIPE_WEBHOOK_SECRET")).thenReturn(WEBHOOK_SECRET);
        request = mock(Request.class);
        response = mock(Response.class);
    }

    private static JsonObject json(Object body) {
        return JsonParser.parseString(String.valueOf(body)).getAsJsonObject();
    }

    @Nested
    class Config {
        @Test
        void returnsPublishableKeyAsJson() throws Exception {
            Object body = Server.config(dotenv).handle(request, response);

            verify(response).type("application/json");
            assertEquals("pk_test_123", json(body).get("publishableKey").getAsString());
        }
    }

    @Nested
    class CreatePaymentIntent {
        private MockedStatic<PaymentIntent> paymentIntent;
        private PaymentIntent intent;

        @BeforeEach
        void mockStripe() {
            paymentIntent = mockStatic(PaymentIntent.class);
            intent = mock(PaymentIntent.class);
            when(intent.getClientSecret()).thenReturn("pi_123_secret_456");
        }

        @AfterEach
        void closeStripe() {
            paymentIntent.close();
        }

        private PaymentIntentCreateParams createWithBody(String body) throws Exception {
            when(request.body()).thenReturn(body);
            paymentIntent
                .when(() -> PaymentIntent.create(any(PaymentIntentCreateParams.class)))
                .thenReturn(intent);

            Object result = Server.createPaymentIntent().handle(request, response);

            ArgumentCaptor<PaymentIntentCreateParams> captor =
                ArgumentCaptor.forClass(PaymentIntentCreateParams.class);
            paymentIntent.verify(() -> PaymentIntent.create(captor.capture()));
            assertEquals("pi_123_secret_456", json(result).get("clientSecret").getAsString());
            verify(response).type("application/json");
            verify(response, never()).status(400);
            return captor.getValue();
        }

        @Test
        void cardHappyPathReturnsClientSecret() throws Exception {
            PaymentIntentCreateParams params =
                createWithBody("{\"paymentMethodType\":\"card\",\"currency\":\"usd\"}");

            assertEquals(5999L, params.getAmount());
            assertEquals("usd", params.getCurrency());
            assertEquals(Collections.singletonList("card"), params.getPaymentMethodTypes());
            assertNull(params.getPaymentMethodOptions());
        }

        @Test
        void linkAlsoEnablesCard() throws Exception {
            PaymentIntentCreateParams params =
                createWithBody("{\"paymentMethodType\":\"link\",\"currency\":\"usd\"}");

            assertEquals(Arrays.asList("link", "card", "link"), params.getPaymentMethodTypes());
        }

        @Test
        void acssDebitAddsMandateOptions() throws Exception {
            PaymentIntentCreateParams params =
                createWithBody("{\"paymentMethodType\":\"acss_debit\",\"currency\":\"cad\"}");

            PaymentIntentCreateParams.PaymentMethodOptions.AcssDebit.MandateOptions mandate =
                ((PaymentIntentCreateParams.PaymentMethodOptions.AcssDebit)
                    params.getPaymentMethodOptions().getAcssDebit()).getMandateOptions();
            assertEquals(
                PaymentIntentCreateParams.PaymentMethodOptions.AcssDebit.MandateOptions.PaymentSchedule.SPORADIC,
                mandate.getPaymentSchedule());
            assertEquals(
                PaymentIntentCreateParams.PaymentMethodOptions.AcssDebit.MandateOptions.TransactionType.PERSONAL,
                mandate.getTransactionType());
        }

        @Test
        void missingCurrencyIsForwardedToStripe() throws Exception {
            PaymentIntentCreateParams params =
                createWithBody("{\"paymentMethodType\":\"card\"}");

            assertNull(params.getCurrency());
        }

        @Test
        void stripeErrorReturns400WithMessage() throws Exception {
            when(request.body()).thenReturn("{\"paymentMethodType\":\"card\",\"currency\":\"usd\"}");
            paymentIntent
                .when(() -> PaymentIntent.create(any(PaymentIntentCreateParams.class)))
                .thenThrow(new InvalidRequestException(
                    "Invalid currency: usd", "currency", "req_123", "invalid_currency", 400, null));

            Object body = Server.createPaymentIntent().handle(request, response);

            verify(response).status(400);
            String message = json(body).getAsJsonObject("error").get("message").getAsString();
            assertTrue(message.startsWith("Invalid currency: usd"), message);
        }

        @Test
        void missingPaymentMethodTypeFailsBeforeCallingStripe() {
            when(request.body()).thenReturn("{\"currency\":\"usd\"}");

            assertThrows(
                NullPointerException.class,
                () -> Server.createPaymentIntent().handle(request, response));
            paymentIntent.verify(() -> PaymentIntent.create(any(PaymentIntentCreateParams.class)), never());
        }

        @Test
        void emptyBodyFailsBeforeCallingStripe() {
            when(request.body()).thenReturn("");

            assertThrows(
                NullPointerException.class,
                () -> Server.createPaymentIntent().handle(request, response));
            paymentIntent.verify(() -> PaymentIntent.create(any(PaymentIntentCreateParams.class)), never());
        }
    }

    @Nested
    class Redirects {
        @Test
        void paymentNextRedirectsToSuccessWithClientSecret() throws Exception {
            try (MockedStatic<PaymentIntent> paymentIntent = mockStatic(PaymentIntent.class)) {
                PaymentIntent intent = mock(PaymentIntent.class);
                when(intent.getClientSecret()).thenReturn("pi_123_secret_456");
                paymentIntent.when(() -> PaymentIntent.retrieve("pi_123")).thenReturn(intent);
                when(request.queryParams("payment_intent")).thenReturn("pi_123");

                Object body = Server.paymentNext().handle(request, response);

                assertEquals("", body);
                verify(response).redirect("/success?payment_intent_client_secret=pi_123_secret_456");
            }
        }

        @Test
        void successRedirectsToStaticPage() throws Exception {
            Object body = Server.success().handle(request, response);

            assertEquals("", body);
            verify(response).redirect("/success.html");
        }
    }

    @Nested
    class WebhookEndpoint {
        private String eventPayload(String type) {
            JsonObject event = new JsonObject();
            event.addProperty("id", "evt_123");
            event.addProperty("object", "event");
            event.addProperty("api_version", Stripe.API_VERSION);
            event.addProperty("type", type);
            event.addProperty("livemode", false);
            event.add("data", new JsonObject());
            return gson.toJson(event);
        }

        private String sign(String payload) throws Exception {
            long timestamp = Webhook.Util.getTimeNow();
            String signature = Webhook.Util.computeHmacSha256(WEBHOOK_SECRET, timestamp + "." + payload);
            return "t=" + timestamp + ",v1=" + signature;
        }

        @Test
        void validSignedSucceededEventReturns200() throws Exception {
            String payload = eventPayload("payment_intent.succeeded");
            when(request.body()).thenReturn(payload);
            when(request.headers("Stripe-Signature")).thenReturn(sign(payload));

            Object body = Server.webhook(dotenv).handle(request, response);

            assertEquals("", body);
            verify(response).status(200);
            verify(response, never()).status(400);
        }

        @Test
        void validSignedFailedEventReturns200() throws Exception {
            String payload = eventPayload("payment_intent.payment_failed");
            when(request.body()).thenReturn(payload);
            when(request.headers("Stripe-Signature")).thenReturn(sign(payload));

            Server.webhook(dotenv).handle(request, response);

            verify(response).status(200);
        }

        @Test
        void invalidSignatureReturns400() throws Exception {
            String payload = eventPayload("payment_intent.succeeded");
            when(request.body()).thenReturn(payload);
            when(request.headers("Stripe-Signature")).thenReturn("t=1,v1=deadbeef");

            Object body = Server.webhook(dotenv).handle(request, response);

            assertEquals("", body);
            verify(response).status(400);
            verify(response, never()).status(200);
        }

        @Test
        void unexpectedEventTypeReturns400() throws Exception {
            String payload = eventPayload("charge.refunded");
            when(request.body()).thenReturn(payload);
            when(request.headers("Stripe-Signature")).thenReturn(sign(payload));

            Server.webhook(dotenv).handle(request, response);

            verify(response).status(400);
            verify(response, never()).status(200);
        }
    }

    @Nested
    class RouteRegistration {
        @AfterEach
        void stopSpark() {
            Spark.stop();
            Spark.awaitStop();
        }

        @Test
        void registeredConfigRouteIsServedOverHttp() throws Exception {
            Spark.port(0);
            Server.registerRoutes(dotenv);
            Spark.awaitInitialization();

            URL url = new URL("http://localhost:" + Spark.port() + "/config");
            HttpURLConnection conn = (HttpURLConnection) url.openConnection();
            try (BufferedReader reader =
                    new BufferedReader(new InputStreamReader(conn.getInputStream()))) {
                String body = reader.readLine();
                assertEquals(200, conn.getResponseCode());
                assertTrue(conn.getContentType().startsWith("application/json"));
                assertEquals("pk_test_123", json(body).get("publishableKey").getAsString());
            }
        }
    }
}
