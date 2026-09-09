<?php

namespace Tests;

final class WebhookTest extends ScriptTestCase
{
    private const SECRET = 'whsec_test_123';

    /**
     * Builds a real `Stripe-Signature` header (t=...,v1=...) for the payload,
     * so \Stripe\Webhook::constructEvent verifies it offline.
     */
    private static function sign(string $payload, string $secret = self::SECRET): string
    {
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);

        return "t={$timestamp},v1={$signature}";
    }

    /**
     * @param array<string, mixed> $event
     */
    private function postWebhook(array $event, ?string $signature = null): ScriptResult
    {
        $payload = json_encode($event, JSON_THROW_ON_ERROR);
        $_SERVER['HTTP_STRIPE_SIGNATURE'] = $signature ?? self::sign($payload);

        return $this->runScript('webhook.php', ['input' => $payload]);
    }

    public function testAcceptsSignedPaymentIntentSucceededEvent(): void
    {
        $result = $this->postWebhook([
            'id' => 'evt_1',
            'object' => 'event',
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => ['id' => 'pi_123', 'object' => 'payment_intent']],
        ]);

        self::assertSame(200, $result->status);
        self::assertSame(['status' => 'success'], $result->json());
        self::assertStringContainsString('Payment received!', $this->errorLog());
    }

    public function testAcceptsSignedPaymentIntentPaymentFailedEvent(): void
    {
        $result = $this->postWebhook([
            'id' => 'evt_2',
            'object' => 'event',
            'type' => 'payment_intent.payment_failed',
            'data' => ['object' => ['id' => 'pi_123', 'object' => 'payment_intent']],
        ]);

        self::assertSame(200, $result->status);
        self::assertSame(['status' => 'success'], $result->json());
        self::assertStringContainsString('Payment failed.', $this->errorLog());
    }

    public function testAcknowledgesUnhandledEventTypesWithoutLogging(): void
    {
        $result = $this->postWebhook([
            'id' => 'evt_3',
            'object' => 'event',
            'type' => 'charge.refunded',
            'data' => ['object' => ['id' => 'ch_123', 'object' => 'charge']],
        ]);

        self::assertSame(200, $result->status);
        self::assertSame(['status' => 'success'], $result->json());
        self::assertSame('', $this->errorLog());
    }

    public function testRejectsSignatureThatDoesNotMatchBody(): void
    {
        $signatureForOtherBody = self::sign(json_encode(['type' => 'payment_intent.succeeded']));

        $result = $this->postWebhook([
            'id' => 'evt_4',
            'object' => 'event',
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => ['id' => 'pi_123', 'object' => 'payment_intent']],
        ], $signatureForOtherBody);

        self::assertSame(403, $result->status);
        self::assertArrayHasKey('error', $result->json());
        self::assertStringContainsString('No signatures found', $result->json()['error']);
        self::assertSame('', $this->errorLog());
    }

    public function testRejectsSignatureFromWrongSecret(): void
    {
        $event = [
            'id' => 'evt_5',
            'object' => 'event',
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => ['id' => 'pi_123', 'object' => 'payment_intent']],
        ];

        $result = $this->postWebhook($event, self::sign(json_encode($event), 'whsec_other'));

        self::assertSame(403, $result->status);
        self::assertStringContainsString('No signatures found', $result->json()['error']);
    }
}
