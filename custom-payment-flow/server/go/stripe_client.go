package main

import (
	"github.com/stripe/stripe-go/v84"
	"github.com/stripe/stripe-go/v84/paymentintent"
	"github.com/stripe/stripe-go/v84/webhook"
)

// stripeClient is the subset of the Stripe SDK the handlers depend on.
type stripeClient interface {
	NewPaymentIntent(params *stripe.PaymentIntentParams) (*stripe.PaymentIntent, error)
	GetPaymentIntent(id string, params *stripe.PaymentIntentParams) (*stripe.PaymentIntent, error)
	ConstructEvent(payload []byte, header string, secret string) (stripe.Event, error)
}

// sdkClient delegates to the stripe-go package-level functions.
type sdkClient struct{}

func (sdkClient) NewPaymentIntent(params *stripe.PaymentIntentParams) (*stripe.PaymentIntent, error) {
	return paymentintent.New(params)
}

func (sdkClient) GetPaymentIntent(id string, params *stripe.PaymentIntentParams) (*stripe.PaymentIntent, error) {
	return paymentintent.Get(id, params)
}

func (sdkClient) ConstructEvent(payload []byte, header string, secret string) (stripe.Event, error) {
	return webhook.ConstructEvent(payload, header, secret)
}

var stripeAPI stripeClient = sdkClient{}
