import { beforeEach, describe, expect, it, vi } from "vitest";
import { NextRequest } from "next/server";

const paymentIntentsCreate = vi.fn();
const paymentIntentsRetrieve = vi.fn();
const customersCreate = vi.fn();
const constructEvent = vi.fn();

vi.mock("stripe", () => {
  class StripeMock {
    paymentIntents = {
      create: paymentIntentsCreate,
      retrieve: paymentIntentsRetrieve,
    };
    customers = { create: customersCreate };
    webhooks = { constructEvent };
    tax = { calculations: { create: vi.fn() } };
  }
  return { default: StripeMock };
});

import { getStripe } from "@/lib/stripe";
import { GET as getConfig } from "@/app/api/config/route";
import { POST as createPaymentIntent } from "@/app/api/create-payment-intent/route";
import { POST as webhook } from "@/app/api/webhook/route";
import { GET as paymentNext } from "@/app/api/payment/next/route";

const BASE = "http://localhost:4242";

function jsonRequest(path: string, body: unknown): NextRequest {
  return new NextRequest(`${BASE}${path}`, {
    method: "POST",
    headers: { "content-type": "application/json" },
    body: JSON.stringify(body),
  });
}

beforeEach(() => {
  vi.clearAllMocks();
  vi.spyOn(console, "log").mockImplementation(() => {});
  process.env.STRIPE_WEBHOOK_SECRET = "whsec_test_placeholder";
});

describe("lib/stripe", () => {
  it("returns a memoized Stripe client", () => {
    expect(getStripe()).toBe(getStripe());
  });
});

describe("GET /api/config", () => {
  it("returns the publishable key", async () => {
    const res = await getConfig();
    expect(res.status).toBe(200);
    expect(await res.json()).toEqual({ publishableKey: "pk_test_placeholder" });
  });
});

describe("POST /api/create-payment-intent", () => {
  const intent = {
    client_secret: "pi_123_secret_abc",
    next_action: { type: "redirect_to_url" },
  };

  it("creates a card PaymentIntent and returns the client secret", async () => {
    paymentIntentsCreate.mockResolvedValue(intent);
    const res = await createPaymentIntent(
      jsonRequest("/api/create-payment-intent", {
        paymentMethodType: "card",
        currency: "usd",
      })
    );
    expect(res.status).toBe(200);
    expect(await res.json()).toEqual({
      clientSecret: "pi_123_secret_abc",
      nextAction: { type: "redirect_to_url" },
    });
    expect(paymentIntentsCreate).toHaveBeenCalledWith({
      payment_method_types: ["card"],
      amount: 5999,
      currency: "usd",
    });
  });

  it("adds card to payment_method_types for link", async () => {
    paymentIntentsCreate.mockResolvedValue(intent);
    await createPaymentIntent(
      jsonRequest("/api/create-payment-intent", {
        paymentMethodType: "link",
        currency: "usd",
      })
    );
    expect(paymentIntentsCreate.mock.calls[0][0].payment_method_types).toEqual([
      "link",
      "card",
    ]);
  });

  it("adds mandate options for acss_debit", async () => {
    paymentIntentsCreate.mockResolvedValue(intent);
    await createPaymentIntent(
      jsonRequest("/api/create-payment-intent", {
        paymentMethodType: "acss_debit",
        currency: "cad",
      })
    );
    expect(paymentIntentsCreate).toHaveBeenCalledWith(
      expect.objectContaining({
        currency: "cad",
        payment_method_options: {
          acss_debit: {
            mandate_options: {
              payment_schedule: "sporadic",
              transaction_type: "personal",
            },
          },
        },
      })
    );
  });

  it("adds default konbini options", async () => {
    paymentIntentsCreate.mockResolvedValue(intent);
    await createPaymentIntent(
      jsonRequest("/api/create-payment-intent", {
        paymentMethodType: "konbini",
        currency: "jpy",
      })
    );
    expect(paymentIntentsCreate.mock.calls[0][0].payment_method_options).toEqual({
      konbini: { product_description: "Tシャツ", expires_after_days: 3 },
    });
  });

  it("creates a customer for customer_balance when none is given", async () => {
    customersCreate.mockResolvedValue({ id: "cus_new" });
    paymentIntentsCreate.mockResolvedValue(intent);
    await createPaymentIntent(
      jsonRequest("/api/create-payment-intent", {
        paymentMethodType: "customer_balance",
        currency: "eur",
      })
    );
    expect(customersCreate).toHaveBeenCalledTimes(1);
    expect(paymentIntentsCreate).toHaveBeenCalledWith(
      expect.objectContaining({
        payment_method_data: { type: "customer_balance" },
        confirm: true,
        customer: "cus_new",
      })
    );
  });

  it("uses the supplied customerId for customer_balance", async () => {
    paymentIntentsCreate.mockResolvedValue(intent);
    await createPaymentIntent(
      jsonRequest("/api/create-payment-intent", {
        paymentMethodType: "customer_balance",
        currency: "eur",
        customerId: "cus_existing",
      })
    );
    expect(customersCreate).not.toHaveBeenCalled();
    expect(paymentIntentsCreate.mock.calls[0][0].customer).toBe("cus_existing");
  });

  it("lets paymentMethodOptions override defaults", async () => {
    paymentIntentsCreate.mockResolvedValue(intent);
    const paymentMethodOptions = { card: { request_three_d_secure: "any" } };
    await createPaymentIntent(
      jsonRequest("/api/create-payment-intent", {
        paymentMethodType: "konbini",
        currency: "jpy",
        paymentMethodOptions,
      })
    );
    expect(paymentIntentsCreate.mock.calls[0][0].payment_method_options).toEqual(
      paymentMethodOptions
    );
  });

  it("returns 400 with the error message when Stripe fails", async () => {
    paymentIntentsCreate.mockRejectedValue(new Error("Invalid currency"));
    const res = await createPaymentIntent(
      jsonRequest("/api/create-payment-intent", {
        paymentMethodType: "card",
        currency: "xxx",
      })
    );
    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ error: { message: "Invalid currency" } });
  });

  it("returns 400 for a malformed JSON body", async () => {
    const res = await createPaymentIntent(
      new NextRequest(`${BASE}/api/create-payment-intent`, {
        method: "POST",
        body: "not json",
      })
    );
    expect(res.status).toBe(400);
    expect(paymentIntentsCreate).not.toHaveBeenCalled();
  });
});

describe("POST /api/webhook", () => {
  const payload = JSON.stringify({
    type: "payment_intent.succeeded",
    data: { object: { id: "pi_123" } },
  });

  function webhookRequest(body: string, signature?: string): NextRequest {
    return new NextRequest(`${BASE}/api/webhook`, {
      method: "POST",
      headers: signature ? { "stripe-signature": signature } : {},
      body,
    });
  }

  it("verifies the signature and acknowledges payment_intent.succeeded", async () => {
    constructEvent.mockReturnValue(JSON.parse(payload));
    const res = await webhook(webhookRequest(payload, "t=1,v1=sig"));
    expect(res.status).toBe(200);
    expect(await res.json()).toEqual({ received: true });
    expect(constructEvent).toHaveBeenCalledWith(
      payload,
      "t=1,v1=sig",
      "whsec_test_placeholder"
    );
    expect(console.log).toHaveBeenCalledWith("Payment captured!");
  });

  it("logs payment_intent.payment_failed", async () => {
    constructEvent.mockReturnValue({
      type: "payment_intent.payment_failed",
      data: {},
    });
    const res = await webhook(webhookRequest(payload, "sig"));
    expect(res.status).toBe(200);
    expect(console.log).toHaveBeenCalledWith("Payment failed.");
  });

  it("returns 400 when signature verification fails", async () => {
    constructEvent.mockImplementation(() => {
      throw new Error("No signatures found matching the expected signature");
    });
    const res = await webhook(webhookRequest(payload, "bad"));
    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({
      error: "No signatures found matching the expected signature",
    });
  });

  it("parses the raw body when no webhook secret is configured", async () => {
    delete process.env.STRIPE_WEBHOOK_SECRET;
    const res = await webhook(webhookRequest(payload));
    expect(res.status).toBe(200);
    expect(await res.json()).toEqual({ received: true });
    expect(constructEvent).not.toHaveBeenCalled();
    expect(console.log).toHaveBeenCalledWith("Payment captured!");
  });

  it("ignores unrelated event types", async () => {
    constructEvent.mockReturnValue({ type: "charge.refunded", data: {} });
    const res = await webhook(webhookRequest(payload, "sig"));
    expect(res.status).toBe(200);
    expect(console.log).not.toHaveBeenCalled();
  });
});

describe("GET /api/payment/next", () => {
  it("returns 400 when payment_intent is missing", async () => {
    const res = await paymentNext(new NextRequest(`${BASE}/api/payment/next`));
    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({
      error: "Missing payment_intent parameter",
    });
  });

  it("redirects to /success with the client secret", async () => {
    paymentIntentsRetrieve.mockResolvedValue({
      client_secret: "pi_123_secret_abc",
    });
    const res = await paymentNext(
      new NextRequest(`${BASE}/api/payment/next?payment_intent=pi_123`)
    );
    expect(res.status).toBe(307);
    expect(res.headers.get("location")).toBe(
      `${BASE}/success?payment_intent_client_secret=pi_123_secret_abc`
    );
    expect(paymentIntentsRetrieve).toHaveBeenCalledWith("pi_123", {
      expand: ["payment_method"],
    });
  });

  it("returns 400 when retrieval fails", async () => {
    paymentIntentsRetrieve.mockRejectedValue(new Error("No such payment_intent"));
    const res = await paymentNext(
      new NextRequest(`${BASE}/api/payment/next?payment_intent=pi_missing`)
    );
    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ error: "No such payment_intent" });
  });
});
