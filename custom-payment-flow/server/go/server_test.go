package main

import (
	"encoding/json"
	"errors"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/stripe/stripe-go/v84"
)

// mockStripe implements stripeClient and records every call so tests can
// assert on the exact parameters the handlers build.
type mockStripe struct {
	newPaymentIntentFn func(params *stripe.PaymentIntentParams) (*stripe.PaymentIntent, error)
	getPaymentIntentFn func(id string, params *stripe.PaymentIntentParams) (*stripe.PaymentIntent, error)
	constructEventFn   func(payload []byte, header string, secret string) (stripe.Event, error)

	newCalls       []*stripe.PaymentIntentParams
	getCalls       []string
	getParams      []*stripe.PaymentIntentParams
	constructCalls []constructEventCall
}

type constructEventCall struct {
	payload []byte
	header  string
	secret  string
}

func (m *mockStripe) NewPaymentIntent(params *stripe.PaymentIntentParams) (*stripe.PaymentIntent, error) {
	m.newCalls = append(m.newCalls, params)
	if m.newPaymentIntentFn == nil {
		return &stripe.PaymentIntent{ClientSecret: "pi_test_secret"}, nil
	}
	return m.newPaymentIntentFn(params)
}

func (m *mockStripe) GetPaymentIntent(id string, params *stripe.PaymentIntentParams) (*stripe.PaymentIntent, error) {
	m.getCalls = append(m.getCalls, id)
	m.getParams = append(m.getParams, params)
	if m.getPaymentIntentFn == nil {
		return &stripe.PaymentIntent{ClientSecret: "pi_test_secret"}, nil
	}
	return m.getPaymentIntentFn(id, params)
}

func (m *mockStripe) ConstructEvent(payload []byte, header string, secret string) (stripe.Event, error) {
	m.constructCalls = append(m.constructCalls, constructEventCall{payload: payload, header: header, secret: secret})
	if m.constructEventFn == nil {
		return stripe.Event{Type: "payment_intent.succeeded"}, nil
	}
	return m.constructEventFn(payload, header, secret)
}

func useMock(t *testing.T) *mockStripe {
	t.Helper()
	m := &mockStripe{}
	prev := stripeAPI
	stripeAPI = m
	t.Cleanup(func() { stripeAPI = prev })
	return m
}

func setTestEnv(t *testing.T) {
	t.Helper()
	t.Setenv("STRIPE_PUBLISHABLE_KEY", "pk_test_fake")
	t.Setenv("STRIPE_SECRET_KEY", "sk_test_fake")
	t.Setenv("STRIPE_WEBHOOK_SECRET", "whsec_test")
}

func postJSON(handler http.HandlerFunc, path, body string) *httptest.ResponseRecorder {
	req := httptest.NewRequest(http.MethodPost, path, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	rr := httptest.NewRecorder()
	handler(rr, req)
	return rr
}

func decodeBody(t *testing.T, rr *httptest.ResponseRecorder) map[string]interface{} {
	t.Helper()
	var out map[string]interface{}
	if err := json.Unmarshal(rr.Body.Bytes(), &out); err != nil {
		t.Fatalf("response is not a JSON object: %v\nbody: %s", err, rr.Body.String())
	}
	return out
}

func errorMessage(t *testing.T, rr *httptest.ResponseRecorder) string {
	t.Helper()
	var resp ErrorResponse
	if err := json.Unmarshal(rr.Body.Bytes(), &resp); err != nil {
		t.Fatalf("response is not an error object: %v\nbody: %s", err, rr.Body.String())
	}
	if resp.Error == nil {
		t.Fatalf("error object missing from %s", rr.Body.String())
	}
	return resp.Error.Message
}

func stringValues(ptrs []*string) []string {
	out := make([]string, 0, len(ptrs))
	for _, p := range ptrs {
		out = append(out, stripe.StringValue(p))
	}
	return out
}

func assertJSONContentType(t *testing.T, rr *httptest.ResponseRecorder) {
	t.Helper()
	if got := rr.Header().Get("Content-Type"); got != "application/json" {
		t.Errorf("Content-Type = %q, want application/json", got)
	}
}

// --- /config ---------------------------------------------------------------

func TestHandleConfig(t *testing.T) {
	setTestEnv(t)

	req := httptest.NewRequest(http.MethodGet, "/config", nil)
	rr := httptest.NewRecorder()
	handleConfig(rr, req)

	if rr.Code != http.StatusOK {
		t.Fatalf("status = %d, want 200", rr.Code)
	}
	assertJSONContentType(t, rr)
	if got := decodeBody(t, rr)["publishableKey"]; got != "pk_test_fake" {
		t.Errorf("publishableKey = %v, want pk_test_fake", got)
	}
}

func TestHandleConfigRejectsNonGET(t *testing.T) {
	rr := postJSON(handleConfig, "/config", "")
	if rr.Code != http.StatusMethodNotAllowed {
		t.Errorf("status = %d, want 405", rr.Code)
	}
}

// --- /create-payment-intent ------------------------------------------------

func TestCreatePaymentIntentCard(t *testing.T) {
	setTestEnv(t)
	m := useMock(t)
	m.newPaymentIntentFn = func(params *stripe.PaymentIntentParams) (*stripe.PaymentIntent, error) {
		return &stripe.PaymentIntent{ClientSecret: "pi_123_secret_abc"}, nil
	}

	rr := postJSON(handleCreatePaymentIntent, "/create-payment-intent",
		`{"paymentMethodType":"card","currency":"usd"}`)

	if rr.Code != http.StatusOK {
		t.Fatalf("status = %d, want 200; body %s", rr.Code, rr.Body.String())
	}
	assertJSONContentType(t, rr)
	if got := decodeBody(t, rr)["clientSecret"]; got != "pi_123_secret_abc" {
		t.Errorf("clientSecret = %v, want pi_123_secret_abc", got)
	}

	if len(m.newCalls) != 1 {
		t.Fatalf("NewPaymentIntent called %d times, want 1", len(m.newCalls))
	}
	params := m.newCalls[0]
	if got := stripe.Int64Value(params.Amount); got != 5999 {
		t.Errorf("Amount = %d, want 5999", got)
	}
	if got := stripe.StringValue(params.Currency); got != "usd" {
		t.Errorf("Currency = %q, want usd", got)
	}
	if got := stringValues(params.PaymentMethodTypes); len(got) != 1 || got[0] != "card" {
		t.Errorf("PaymentMethodTypes = %v, want [card]", got)
	}
	if params.PaymentMethodOptions != nil {
		t.Errorf("PaymentMethodOptions should be nil for card, got %+v", params.PaymentMethodOptions)
	}
}

func TestCreatePaymentIntentLinkAddsCard(t *testing.T) {
	m := useMock(t)

	rr := postJSON(handleCreatePaymentIntent, "/create-payment-intent",
		`{"paymentMethodType":"link","currency":"usd"}`)

	if rr.Code != http.StatusOK {
		t.Fatalf("status = %d, want 200", rr.Code)
	}
	got := stringValues(m.newCalls[0].PaymentMethodTypes)
	if len(got) != 2 || got[0] != "link" || got[1] != "card" {
		t.Errorf("PaymentMethodTypes = %v, want [link card]", got)
	}
	if m.newCalls[0].PaymentMethodOptions != nil {
		t.Errorf("PaymentMethodOptions should be nil for link")
	}
}

func TestCreatePaymentIntentACSSDebitMandateOptions(t *testing.T) {
	m := useMock(t)

	rr := postJSON(handleCreatePaymentIntent, "/create-payment-intent",
		`{"paymentMethodType":"acss_debit","currency":"cad"}`)

	if rr.Code != http.StatusOK {
		t.Fatalf("status = %d, want 200", rr.Code)
	}
	params := m.newCalls[0]
	if got := stringValues(params.PaymentMethodTypes); len(got) != 1 || got[0] != "acss_debit" {
		t.Errorf("PaymentMethodTypes = %v, want [acss_debit]", got)
	}
	if got := stripe.StringValue(params.Currency); got != "cad" {
		t.Errorf("Currency = %q, want cad", got)
	}
	if params.PaymentMethodOptions == nil || params.PaymentMethodOptions.ACSSDebit == nil ||
		params.PaymentMethodOptions.ACSSDebit.MandateOptions == nil {
		t.Fatalf("expected ACSS debit mandate options, got %+v", params.PaymentMethodOptions)
	}
	mo := params.PaymentMethodOptions.ACSSDebit.MandateOptions
	if got := stripe.StringValue(mo.PaymentSchedule); got != "sporadic" {
		t.Errorf("PaymentSchedule = %q, want sporadic", got)
	}
	if got := stripe.StringValue(mo.TransactionType); got != "personal" {
		t.Errorf("TransactionType = %q, want personal", got)
	}
}

func TestCreatePaymentIntentOtherMethodsGetNoMandateOptions(t *testing.T) {
	for _, pmt := range []string{"sepa_debit", "us_bank_account", "klarna"} {
		t.Run(pmt, func(t *testing.T) {
			m := useMock(t)
			rr := postJSON(handleCreatePaymentIntent, "/create-payment-intent",
				`{"paymentMethodType":"`+pmt+`","currency":"eur"}`)
			if rr.Code != http.StatusOK {
				t.Fatalf("status = %d, want 200", rr.Code)
			}
			if got := stringValues(m.newCalls[0].PaymentMethodTypes); len(got) != 1 || got[0] != pmt {
				t.Errorf("PaymentMethodTypes = %v, want [%s]", got, pmt)
			}
			if m.newCalls[0].PaymentMethodOptions != nil {
				t.Errorf("PaymentMethodOptions should be nil for %s", pmt)
			}
		})
	}
}

func TestCreatePaymentIntentMissingParamsForwardedEmpty(t *testing.T) {
	m := useMock(t)

	rr := postJSON(handleCreatePaymentIntent, "/create-payment-intent", `{}`)

	if rr.Code != http.StatusOK {
		t.Fatalf("status = %d, want 200 (server forwards empty params to Stripe)", rr.Code)
	}
	params := m.newCalls[0]
	if got := stripe.StringValue(params.Currency); got != "" {
		t.Errorf("Currency = %q, want empty", got)
	}
	if got := stringValues(params.PaymentMethodTypes); len(got) != 1 || got[0] != "" {
		t.Errorf("PaymentMethodTypes = %v, want [\"\"]", got)
	}
}

func TestCreatePaymentIntentInvalidJSONStillCallsStripe(t *testing.T) {
	m := useMock(t)

	rr := postJSON(handleCreatePaymentIntent, "/create-payment-intent", `not json`)

	if rr.Code != http.StatusOK {
		t.Fatalf("status = %d, want 200 (decode errors are ignored)", rr.Code)
	}
	if len(m.newCalls) != 1 {
		t.Fatalf("NewPaymentIntent called %d times, want 1", len(m.newCalls))
	}
}

func TestCreatePaymentIntentStripeErrorReturns400(t *testing.T) {
	m := useMock(t)
	stripeErr := &stripe.Error{
		Code:           stripe.ErrorCodeParameterInvalidEmpty,
		Msg:            "You must provide a currency.",
		Param:          "currency",
		HTTPStatusCode: 400,
		Type:           stripe.ErrorTypeInvalidRequest,
	}
	m.newPaymentIntentFn = func(params *stripe.PaymentIntentParams) (*stripe.PaymentIntent, error) {
		return nil, stripeErr
	}

	rr := postJSON(handleCreatePaymentIntent, "/create-payment-intent",
		`{"paymentMethodType":"card","currency":""}`)

	if rr.Code != http.StatusBadRequest {
		t.Fatalf("status = %d, want 400", rr.Code)
	}
	assertJSONContentType(t, rr)
	msg := errorMessage(t, rr)
	if msg != stripeErr.Error() {
		t.Errorf("error.message = %q, want %q", msg, stripeErr.Error())
	}
	if !strings.Contains(msg, "You must provide a currency.") {
		t.Errorf("error.message %q should include the Stripe message", msg)
	}
}

func TestCreatePaymentIntentGenericErrorReturns500(t *testing.T) {
	m := useMock(t)
	m.newPaymentIntentFn = func(params *stripe.PaymentIntentParams) (*stripe.PaymentIntent, error) {
		return nil, errors.New("network down")
	}

	rr := postJSON(handleCreatePaymentIntent, "/create-payment-intent",
		`{"paymentMethodType":"card","currency":"usd"}`)

	if rr.Code != http.StatusInternalServerError {
		t.Fatalf("status = %d, want 500", rr.Code)
	}
	if msg := errorMessage(t, rr); msg != "Unknown server error" {
		t.Errorf("error.message = %q, want Unknown server error", msg)
	}
}

// --- /success and /payment/next --------------------------------------------

func TestHandleSuccessRedirects(t *testing.T) {
	req := httptest.NewRequest(http.MethodGet, "/success", nil)
	rr := httptest.NewRecorder()
	handleSuccess(rr, req)

	if rr.Code != http.StatusSeeOther {
		t.Fatalf("status = %d, want 303", rr.Code)
	}
	if got := rr.Header().Get("Location"); got != "/success.html" {
		t.Errorf("Location = %q, want /success.html", got)
	}
}

func TestHandleSuccessRejectsNonGET(t *testing.T) {
	rr := postJSON(handleSuccess, "/success", "")
	if rr.Code != http.StatusMethodNotAllowed {
		t.Errorf("status = %d, want 405", rr.Code)
	}
}

func TestHandlePaymentNextRetrievesIntentAndRedirects(t *testing.T) {
	m := useMock(t)
	m.getPaymentIntentFn = func(id string, params *stripe.PaymentIntentParams) (*stripe.PaymentIntent, error) {
		return &stripe.PaymentIntent{ID: id, ClientSecret: id + "_secret_xyz"}, nil
	}

	req := httptest.NewRequest(http.MethodGet, "/payment/next?payment_intent=pi_123", nil)
	rr := httptest.NewRecorder()
	handlePaymentNext(rr, req)

	if rr.Code != http.StatusSeeOther {
		t.Fatalf("status = %d, want 303", rr.Code)
	}
	if got := rr.Header().Get("Location"); got != "/success?payment_intent_client_secret=pi_123_secret_xyz" {
		t.Errorf("Location = %q", got)
	}
	if len(m.getCalls) != 1 || m.getCalls[0] != "pi_123" {
		t.Errorf("GetPaymentIntent calls = %v, want [pi_123]", m.getCalls)
	}
	if m.getParams[0] != nil {
		t.Errorf("GetPaymentIntent params = %+v, want nil", m.getParams[0])
	}
}

func TestHandlePaymentNextRejectsNonGET(t *testing.T) {
	m := useMock(t)
	rr := postJSON(handlePaymentNext, "/payment/next", "")
	if rr.Code != http.StatusMethodNotAllowed {
		t.Errorf("status = %d, want 405", rr.Code)
	}
	if len(m.getCalls) != 0 {
		t.Errorf("GetPaymentIntent should not be called, got %v", m.getCalls)
	}
}

// --- /webhook ----------------------------------------------------------------

func TestHandleWebhookValidSignedEvent(t *testing.T) {
	setTestEnv(t)
	m := useMock(t)
	m.constructEventFn = func(payload []byte, header string, secret string) (stripe.Event, error) {
		return stripe.Event{ID: "evt_1", Type: "payment_intent.succeeded"}, nil
	}

	rawBody := `{"id":"evt_1","type":"payment_intent.succeeded","data":{"object":{"id":"pi_1"}}}`
	req := httptest.NewRequest(http.MethodPost, "/webhook", strings.NewReader(rawBody))
	req.Header.Set("Stripe-Signature", "t=1,v1=abc")
	rr := httptest.NewRecorder()
	handleWebhook(rr, req)

	if rr.Code != http.StatusOK {
		t.Fatalf("status = %d, want 200; body %s", rr.Code, rr.Body.String())
	}
	assertJSONContentType(t, rr)
	if got := strings.TrimSpace(rr.Body.String()); got != "null" {
		t.Errorf("body = %q, want null", got)
	}

	if len(m.constructCalls) != 1 {
		t.Fatalf("ConstructEvent called %d times, want 1", len(m.constructCalls))
	}
	call := m.constructCalls[0]
	if string(call.payload) != rawBody {
		t.Errorf("ConstructEvent payload = %q, want raw body %q", call.payload, rawBody)
	}
	if call.header != "t=1,v1=abc" {
		t.Errorf("ConstructEvent header = %q, want t=1,v1=abc", call.header)
	}
	if call.secret != "whsec_test" {
		t.Errorf("ConstructEvent secret = %q, want whsec_test", call.secret)
	}
}

func TestHandleWebhookCheckoutSessionCompleted(t *testing.T) {
	setTestEnv(t)
	m := useMock(t)
	m.constructEventFn = func(payload []byte, header string, secret string) (stripe.Event, error) {
		return stripe.Event{ID: "evt_2", Type: "checkout.session.completed"}, nil
	}

	req := httptest.NewRequest(http.MethodPost, "/webhook", strings.NewReader(`{"id":"evt_2"}`))
	req.Header.Set("Stripe-Signature", "t=1,v1=abc")
	rr := httptest.NewRecorder()
	handleWebhook(rr, req)

	if rr.Code != http.StatusOK {
		t.Fatalf("status = %d, want 200", rr.Code)
	}
}

func TestHandleWebhookRejectsNonPOST(t *testing.T) {
	m := useMock(t)
	req := httptest.NewRequest(http.MethodGet, "/webhook", nil)
	rr := httptest.NewRecorder()
	handleWebhook(rr, req)

	if rr.Code != http.StatusMethodNotAllowed {
		t.Errorf("status = %d, want 405", rr.Code)
	}
	if len(m.constructCalls) != 0 {
		t.Errorf("ConstructEvent should not be called, got %d calls", len(m.constructCalls))
	}
}

// --- JSON helpers ------------------------------------------------------------

func TestWriteJSONEncodeFailureReturns500(t *testing.T) {
	rr := httptest.NewRecorder()
	writeJSON(rr, make(chan int))

	if rr.Code != http.StatusInternalServerError {
		t.Errorf("status = %d, want 500", rr.Code)
	}
}

type failingWriter struct{ http.ResponseWriter }

func (failingWriter) Write([]byte) (int, error) { return 0, errors.New("client went away") }

func TestWriteJSONWriteFailureDoesNotPanic(t *testing.T) {
	rr := httptest.NewRecorder()
	writeJSON(failingWriter{rr}, map[string]string{"ok": "true"})

	if got := rr.Header().Get("Content-Type"); got != "application/json" {
		t.Errorf("Content-Type = %q, want application/json", got)
	}
	if rr.Body.Len() != 0 {
		t.Errorf("body should be empty when the write fails, got %q", rr.Body.String())
	}
}

func TestWriteJSONErrorMessage(t *testing.T) {
	rr := httptest.NewRecorder()
	writeJSONErrorMessage(rr, "boom", http.StatusTeapot)

	if rr.Code != http.StatusTeapot {
		t.Errorf("status = %d, want 418", rr.Code)
	}
	if msg := errorMessage(t, rr); msg != "boom" {
		t.Errorf("error.message = %q, want boom", msg)
	}
}
