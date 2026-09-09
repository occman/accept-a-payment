<?php

namespace App\Tests;

use App\WebhookHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WebhookTest extends TestCase
{
    private const WEBHOOK_SECRET = 'whsec_placeholder';

    private static function signedHeader(string $payload, string $secret): string
    {
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);

        return 't=' . $timestamp . ',v1=' . $signature;
    }

    private static function payload(string $type): string
    {
        return json_encode([
            'id' => 'evt_123',
            'object' => 'event',
            'type' => $type,
            'data' => ['object' => ['id' => 'pi_123', 'object' => 'payment_intent']],
        ]);
    }

    public function testConstructEventAcceptsValidSignature(): void
    {
        $payload = self::payload('payment_intent.succeeded');

        $event = WebhookHandler::constructEvent(
            $payload,
            self::signedHeader($payload, self::WEBHOOK_SECRET),
            self::WEBHOOK_SECRET
        );

        self::assertInstanceOf(\Stripe\Event::class, $event);
        self::assertSame('payment_intent.succeeded', $event->type);
    }

    public function testConstructEventRejectsBadSignature(): void
    {
        $payload = self::payload('payment_intent.succeeded');

        $this->expectException(\Stripe\Exception\SignatureVerificationException::class);

        WebhookHandler::constructEvent(
            $payload,
            self::signedHeader($payload, 'whsec_wrong'),
            self::WEBHOOK_SECRET
        );
    }

    public function testConstructEventRejectsUnsignedPayload(): void
    {
        $this->expectException(\Stripe\Exception\SignatureVerificationException::class);

        WebhookHandler::constructEvent(self::payload('payment_intent.succeeded'), '', self::WEBHOOK_SECRET);
    }

    public function testConstructEventRejectsMalformedPayload(): void
    {
        $payload = 'not json';

        $this->expectException(\UnexpectedValueException::class);

        WebhookHandler::constructEvent(
            $payload,
            self::signedHeader($payload, self::WEBHOOK_SECRET),
            self::WEBHOOK_SECRET
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function eventTypes(): iterable
    {
        yield 'payment succeeded' => ['payment_intent.succeeded'];
        yield 'payment failed' => ['payment_intent.payment_failed'];
        yield 'unhandled event' => ['customer.created'];
    }

    #[DataProvider('eventTypes')]
    public function testHandleReturnsSuccessForAnyEvent(string $type): void
    {
        $event = \Stripe\Event::constructFrom([
            'id' => 'evt_123',
            'object' => 'event',
            'type' => $type,
            'data' => ['object' => ['id' => 'pi_123', 'object' => 'payment_intent']],
        ]);

        self::assertSame(['status' => 'success'], WebhookHandler::handle($event));
    }
}
