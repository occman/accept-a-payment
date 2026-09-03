package main

import (
	"encoding/json"
	"errors"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/stripe/stripe-go/v84"
	"github.com/stripe/stripe-go/v84/webhook"
)

// mockStripeClient records the params passed to it and returns canned results.
type mockStripeClient struct {
	newParams *stripe.PaymentIntentParams
	newResult *stripe.PaymentIntent
	newErr    error

	getID     string
	getResult *stripe.PaymentIntent
	getErr    error
}

func (m *mockStripeClient) NewPaymentIntent(params *stripe.PaymentIntentParams) (*stripe.PaymentIntent, error) {
	m.newParams = params
	return m.newResult, m.newErr
}

func (m *mockStripeClient) GetPaymentIntent(id string, params *stripe.PaymentIntentParams) (*stripe.PaymentIntent, error) {
	m.getID = id
	return m.getResult, m.getErr
}

func useMock(t *testing.T, m *mockStripeClient) {
	t.Helper()
	prev := stripeAPI
	stripeAPI = m
	t.Cleanup(func() { stripeAPI = prev })
}

func decodeJSON(t *testing.T, rr *httptest.ResponseRecorder, v interface{}) {
	t.Helper()
	if ct := rr.Header().Get("Content-Type"); ct != "application/json" {
		t.Fatalf("Content-Type = %q, want application/json", ct)
	}
	if err := json.Unmarshal(rr.Body.Bytes(), v); err != nil {
		t.Fatalf("invalid JSON body %q: %v", rr.Body.String(), err)
	}
}

func strs(ps []*string) []string {
	out := make([]string, 0, len(ps))
	for _, p := range ps {
		out = append(out, stripe.StringValue(p))
	}
	return out
}

// --- /config ---

func TestHandleConfig_ReturnsPublishableKey(t *testing.T) {
	t.Setenv("STRIPE_PUBLISHABLE_KEY", "pk_test_123")

	rr := httptest.NewRecorder()
	handleConfig(rr, httptest.NewRequest(http.MethodGet, "/config", nil))

	if rr.Code != http.StatusOK {
		t.Fatalf("status = %d, want 200", rr.Code)
	}
	var body struct {
		PublishableKey string `json:"publishableKey"`
	}
	decodeJSON(t, rr, &body)
	if body.PublishableKey != "pk_test_123" {
		t.Errorf("publishableKey = %q, want pk_test_123", body.PublishableKey)
	}
}

func TestHandleConfig_RejectsNonGET(t *testing.T) {
	rr := httptest.NewRecorder()
	handleConfig(rr, httptest.NewRequest(http.MethodPost, "/config", nil))

	if rr.Code != http.StatusMethodNotAllowed {
		t.Fatalf("status = %d, want 405", rr.Code)
	}
}

// --- /create-payment-intent ---

func postPaymentIntent(body string) *httptest.ResponseRecorder {
	rr := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodPost, "/create-payment-intent", strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	handleCreatePaymentIntent(rr, req)
	return rr
}

func TestHandleCreatePaymentIntent_HappyPath(t *testing.T) {
	m := &mockStripeClient{newResult: &stripe.PaymentIntent{ClientSecret: "pi_123_secret_456"}}
	useMock(t, m)

	rr := postPaymentIntent(`{"currency":"usd","paymentMethodType":"card"}`)

	if rr.Code != http.StatusOK {
		t.Fatalf("status = %d, want 200; body=%s", rr.Code, rr.Body.String())
	}
	var body struct {
		ClientSecret string `json:"clientSecret"`
	}
	decodeJSON(t, rr, &body)
	if body.ClientSecret != "pi_123_secret_456" {
		t.Errorf("clientSecret = %q", body.ClientSecret)
	}

	p := m.newParams
	if p == nil {
		t.Fatal("stripe client was not called")
	}
	if got := stripe.Int64Value(p.Amount); got != 5999 {
		t.Errorf("Amount = %d, want 5999", got)
	}
	if got := stripe.StringValue(p.Currency); got != "usd" {
		t.Errorf("Currency = %q, want usd", got)
	}
	if got := strs(p.PaymentMethodTypes); len(got) != 1 || got[0] != "card" {
		t.Errorf("PaymentMethodTypes = %v, want [card]", got)
	}
	if p.PaymentMethodOptions != nil {
		t.Errorf("PaymentMethodOptions should be nil for card, got %+v", p.PaymentMethodOptions)
	}
}

func TestHandleCreatePaymentIntent_LinkAddsCard(t *testing.T) {
	m := &mockStripeClient{newResult: &stripe.PaymentIntent{ClientSecret: "cs"}}
	useMock(t, m)

	rr := postPaymentIntent(`{"currency":"usd","paymentMethodType":"link"}`)

	if rr.Code != http.StatusOK {
		t.Fatalf("status = %d, want 200", rr.Code)
	}
	got := strs(m.newParams.PaymentMethodTypes)
	if len(got) != 2 || got[0] != "link" || got[1] != "card" {
		t.Errorf("PaymentMethodTypes = %v, want [link card]", got)
	}
}

func TestHandleCreatePaymentIntent_ACSSDebitAddsMandateOptions(t *testing.T) {
	m := &mockStripeClient{newResult: &stripe.PaymentIntent{ClientSecret: "cs"}}
	useMock(t, m)

	rr := postPaymentIntent(`{"currency":"cad","paymentMethodType":"acss_debit"}`)

	if rr.Code != http.StatusOK {
		t.Fatalf("status = %d, want 200", rr.Code)
	}
	opts := m.newParams.PaymentMethodOptions
	if opts == nil || opts.ACSSDebit == nil || opts.ACSSDebit.MandateOptions == nil {
		t.Fatalf("expected ACSS mandate options, got %+v", opts)
	}
	mo := opts.ACSSDebit.MandateOptions
	if stripe.StringValue(mo.PaymentSchedule) != "sporadic" || stripe.StringValue(mo.TransactionType) != "personal" {
		t.Errorf("MandateOptions = %+v", mo)
	}
}

func TestHandleCreatePaymentIntent_MissingParamsForwardedAndStripeErrorIs400(t *testing.T) {
	m := &mockStripeClient{newErr: &stripe.Error{
		Type: stripe.ErrorTypeInvalidRequest,
		Msg:  "Missing required param: currency.",
	}}
	useMock(t, m)

	rr := postPaymentIntent(`{}`)

	if rr.Code != http.StatusBadRequest {
		t.Fatalf("status = %d, want 400", rr.Code)
	}
	if got := stripe.StringValue(m.newParams.Currency); got != "" {
		t.Errorf("Currency = %q, want empty", got)
	}
	if got := strs(m.newParams.PaymentMethodTypes); len(got) != 1 || got[0] != "" {
		t.Errorf("PaymentMethodTypes = %v, want [\"\"]", got)
	}
	var body ErrorResponse
	decodeJSON(t, rr, &body)
	if body.Error == nil || !strings.Contains(body.Error.Message, "Missing required param: currency.") {
		t.Errorf("error body = %+v", body)
	}
}

func TestHandleCreatePaymentIntent_MalformedJSONStillCallsStripe(t *testing.T) {
	m := &mockStripeClient{newErr: &stripe.Error{Type: stripe.ErrorTypeInvalidRequest, Msg: "Invalid currency"}}
	useMock(t, m)

	rr := postPaymentIntent(`not json`)

	if rr.Code != http.StatusBadRequest {
		t.Fatalf("status = %d, want 400", rr.Code)
	}
	if m.newParams == nil {
		t.Fatal("stripe client was not called")
	}
}

func TestHandleCreatePaymentIntent_StripeCardError(t *testing.T) {
	m := &mockStripeClient{newErr: &stripe.Error{
		Type: stripe.ErrorTypeCard,
		Code: stripe.ErrorCodeCardDeclined,
		Msg:  "Your card was declined.",
	}}
	useMock(t, m)

	rr := postPaymentIntent(`{"currency":"usd","paymentMethodType":"card"}`)

	if rr.Code != http.StatusBadRequest {
		t.Fatalf("status = %d, want 400", rr.Code)
	}
	var body ErrorResponse
	decodeJSON(t, rr, &body)
	if body.Error == nil || !strings.Contains(body.Error.Message, "Your card was declined.") {
		t.Errorf("error body = %+v", body)
	}
}

func TestHandleCreatePaymentIntent_NonStripeErrorIs500(t *testing.T) {
	useMock(t, &mockStripeClient{newErr: errors.New("connection refused")})

	rr := postPaymentIntent(`{"currency":"usd","paymentMethodType":"card"}`)

	if rr.Code != http.StatusInternalServerError {
		t.Fatalf("status = %d, want 500", rr.Code)
	}
	var body ErrorResponse
	decodeJSON(t, rr, &body)
	if body.Error == nil || body.Error.Message != "Unknown server error" {
		t.Errorf("error body = %+v", body)
	}
}

// --- /success and /payment/next ---

func TestHandleSuccess_Redirects(t *testing.T) {
	rr := httptest.NewRecorder()
	handleSuccess(rr, httptest.NewRequest(http.MethodGet, "/success", nil))

	if rr.Code != http.StatusSeeOther {
		t.Fatalf("status = %d, want 303", rr.Code)
	}
	if loc := rr.Header().Get("Location"); loc != "/success.html" {
		t.Errorf("Location = %q", loc)
	}
}

func TestHandleSuccess_RejectsNonGET(t *testing.T) {
	rr := httptest.NewRecorder()
	handleSuccess(rr, httptest.NewRequest(http.MethodPost, "/success", nil))
	if rr.Code != http.StatusMethodNotAllowed {
		t.Fatalf("status = %d, want 405", rr.Code)
	}
}

func TestHandlePaymentNext_RedirectsWithClientSecret(t *testing.T) {
	m := &mockStripeClient{getResult: &stripe.PaymentIntent{ClientSecret: "pi_abc_secret_xyz"}}
	useMock(t, m)

	rr := httptest.NewRecorder()
	handlePaymentNext(rr, httptest.NewRequest(http.MethodGet, "/payment/next?payment_intent=pi_abc", nil))

	if rr.Code != http.StatusSeeOther {
		t.Fatalf("status = %d, want 303", rr.Code)
	}
	if m.getID != "pi_abc" {
		t.Errorf("retrieved id = %q, want pi_abc", m.getID)
	}
	if loc := rr.Header().Get("Location"); loc != "/success?payment_intent_client_secret=pi_abc_secret_xyz" {
		t.Errorf("Location = %q", loc)
	}
}

func TestHandlePaymentNext_RejectsNonGET(t *testing.T) {
	rr := httptest.NewRecorder()
	handlePaymentNext(rr, httptest.NewRequest(http.MethodPost, "/payment/next", nil))
	if rr.Code != http.StatusMethodNotAllowed {
		t.Fatalf("status = %d, want 405", rr.Code)
	}
}

// --- helpers ---

func TestWriteJSON_UnencodableValueIs500(t *testing.T) {
	rr := httptest.NewRecorder()
	writeJSON(rr, make(chan int))
	if rr.Code != http.StatusInternalServerError {
		t.Fatalf("status = %d, want 500", rr.Code)
	}
}

// --- /webhook ---

const webhookSecret = "whsec_test_secret"

func signedWebhookRequest(t *testing.T, payload string) *http.Request {
	t.Helper()
	signed := webhook.GenerateTestSignedPayload(&webhook.UnsignedPayload{
		Payload:   []byte(payload),
		Secret:    webhookSecret,
		Timestamp: time.Now(),
	})
	req := httptest.NewRequest(http.MethodPost, "/webhook", strings.NewReader(payload))
	req.Header.Set("Stripe-Signature", signed.Header)
	return req
}

func TestHandleWebhook_ValidSignature(t *testing.T) {
	t.Setenv("STRIPE_WEBHOOK_SECRET", webhookSecret)
	payload := `{"id":"evt_1","object":"event","api_version":"` + stripe.APIVersion + `","type":"checkout.session.completed","data":{"object":{"id":"cs_1","object":"checkout.session"}}}`

	rr := httptest.NewRecorder()
	handleWebhook(rr, signedWebhookRequest(t, payload))

	if rr.Code != http.StatusOK {
		t.Fatalf("status = %d, want 200; body=%s", rr.Code, rr.Body.String())
	}
	if got := strings.TrimSpace(rr.Body.String()); got != "null" {
		t.Errorf("body = %q, want null", got)
	}
}

func TestHandleWebhook_InvalidSignature(t *testing.T) {
	t.Setenv("STRIPE_WEBHOOK_SECRET", webhookSecret)

	rr := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodPost, "/webhook", strings.NewReader(`{"type":"checkout.session.completed"}`))
	req.Header.Set("Stripe-Signature", "t=1,v1=deadbeef")
	handleWebhook(rr, req)

	if rr.Code != http.StatusBadRequest {
		t.Fatalf("status = %d, want 400", rr.Code)
	}
}

func TestHandleWebhook_RejectsNonPOST(t *testing.T) {
	rr := httptest.NewRecorder()
	handleWebhook(rr, httptest.NewRequest(http.MethodGet, "/webhook", nil))
	if rr.Code != http.StatusMethodNotAllowed {
		t.Fatalf("status = %d, want 405", rr.Code)
	}
}
