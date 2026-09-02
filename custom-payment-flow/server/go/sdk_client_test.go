package main

import (
	"bytes"
	"encoding/json"
	"net/http"
	"testing"

	"github.com/stripe/stripe-go/v84"
	"github.com/stripe/stripe-go/v84/webhook"
)

// fakeBackend replaces the stripe-go HTTP backend so sdkClient can be
// exercised without network access or API keys.
type fakeBackend struct {
	method   string
	path     string
	key      string
	response string
}

func (b *fakeBackend) Call(method, path, key string, params stripe.ParamsContainer, v stripe.LastResponseSetter) error {
	b.method, b.path, b.key = method, path, key
	return json.Unmarshal([]byte(b.response), v)
}

func (b *fakeBackend) CallStreaming(method, path, key string, params stripe.ParamsContainer, v stripe.StreamingLastResponseSetter) error {
	return nil
}

func (b *fakeBackend) CallRaw(method, path, key string, body []byte, params *stripe.Params, v stripe.LastResponseSetter) error {
	return nil
}

func (b *fakeBackend) CallMultipart(method, path, key, boundary string, body *bytes.Buffer, params *stripe.Params, v stripe.LastResponseSetter) error {
	return nil
}

func (b *fakeBackend) SetMaxNetworkRetries(maxNetworkRetries int64) {}

func useFakeBackend(t *testing.T, response string) *fakeBackend {
	t.Helper()
	prev := stripe.GetBackend(stripe.APIBackend)
	prevKey := stripe.Key
	b := &fakeBackend{response: response}
	stripe.SetBackend(stripe.APIBackend, b)
	stripe.Key = "sk_test_fake"
	t.Cleanup(func() {
		stripe.SetBackend(stripe.APIBackend, prev)
		stripe.Key = prevKey
	})
	return b
}

func TestSDKClientNewPaymentIntent(t *testing.T) {
	b := useFakeBackend(t, `{"id":"pi_1","client_secret":"pi_1_secret"}`)

	pi, err := sdkClient{}.NewPaymentIntent(&stripe.PaymentIntentParams{Amount: stripe.Int64(5999)})
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if pi.ClientSecret != "pi_1_secret" {
		t.Errorf("ClientSecret = %q, want pi_1_secret", pi.ClientSecret)
	}
	if b.method != http.MethodPost || b.path != "/v1/payment_intents" {
		t.Errorf("request = %s %s, want POST /v1/payment_intents", b.method, b.path)
	}
	if b.key != "sk_test_fake" {
		t.Errorf("key = %q, want sk_test_fake", b.key)
	}
}

func TestSDKClientGetPaymentIntent(t *testing.T) {
	b := useFakeBackend(t, `{"id":"pi_123","client_secret":"pi_123_secret"}`)

	pi, err := sdkClient{}.GetPaymentIntent("pi_123", nil)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if pi.ClientSecret != "pi_123_secret" {
		t.Errorf("ClientSecret = %q, want pi_123_secret", pi.ClientSecret)
	}
	if b.method != http.MethodGet || b.path != "/v1/payment_intents/pi_123" {
		t.Errorf("request = %s %s, want GET /v1/payment_intents/pi_123", b.method, b.path)
	}
}

func TestSDKClientConstructEventVerifiesValidSignature(t *testing.T) {
	// ConstructEvent rejects events whose api_version differs from the SDK's pinned version.
	payload := []byte(`{"id":"evt_1","object":"event","api_version":"` + stripe.APIVersion +
		`","type":"payment_intent.succeeded","data":{"object":{"id":"pi_1"}}}`)
	signed := webhook.GenerateTestSignedPayload(&webhook.UnsignedPayload{Payload: payload, Secret: "whsec_test"})

	event, err := sdkClient{}.ConstructEvent(payload, signed.Header, "whsec_test")
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if event.Type != "payment_intent.succeeded" {
		t.Errorf("event.Type = %q, want payment_intent.succeeded", event.Type)
	}
	if event.ID != "evt_1" {
		t.Errorf("event.ID = %q, want evt_1", event.ID)
	}
}
