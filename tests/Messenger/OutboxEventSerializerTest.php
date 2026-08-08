<?php

declare(strict_types=1);

namespace App\Tests\Messenger;

use App\Message\OutboxEvent;
use App\Messenger\OutboxEventSerializer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Retry\MultiplierRetryStrategy;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;

final class OutboxEventSerializerTest extends TestCase
{
    public function testCanonicalV1FixtureDecodesAsCurrentContract(): void
    {
        $fixture = file_get_contents(__DIR__.'/../Fixtures/outbox-event-v1.json');
        self::assertIsString($fixture);

        $message = (new OutboxEventSerializer())->decode(['body' => $fixture, 'headers' => []])->getMessage();
        self::assertInstanceOf(OutboxEvent::class, $message);
        self::assertSame(OutboxEvent::SCHEMA_VERSION, $message->schemaVersion);
        self::assertSame(OutboxEvent::SOURCE, $message->source);
        self::assertSame('0198-contract-v1', $message->messageId);
        self::assertSame('wallet.funding.succeeded', $message->type);
        self::assertSame(['amount' => 1250, 'currency' => 'USD'], $message->payload);
    }

    public function testEncodeUsesStableExternalJsonContract(): void
    {
        $serializer = new OutboxEventSerializer();
        $event = new OutboxEvent(
            messageId: '0198-encoded',
            type: 'wallet.funding.succeeded',
            deduplicationKey: 'funding:123',
            payload: ['amount' => 1250, 'currency' => 'USD'],
            ledgerTransactionId: 'ledger-123',
            providerEventExternalId: null,
            occurredAt: '2026-08-07T00:58:00-05:00',
            correlationId: 'corr-123',
            causationId: 'cause-456',
        );

        $encoded = $serializer->encode(new Envelope($event));
        self::assertSame('application/json', $encoded['headers']['Content-Type']);
        self::assertSame('1', $encoded['headers']['X-Walleting-Event-Schema']);
        self::assertSame('wallet.funding.succeeded', $encoded['headers']['X-Walleting-Event-Type']);

        $body = json_decode($encoded['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([
            'schema_version' => 1,
            'source' => 'walleting',
            'message_id' => '0198-encoded',
            'type' => 'wallet.funding.succeeded',
            'deduplication_key' => 'funding:123',
            'occurred_at' => '2026-08-07T00:58:00-05:00',
            'correlation_id' => 'corr-123',
            'causation_id' => 'cause-456',
            'ledger_transaction_id' => 'ledger-123',
            'provider_event_external_id' => null,
            'payload' => ['amount' => 1250, 'currency' => 'USD'],
        ], $body);
    }

    public function testDecodeRejectsUnsupportedFutureSchemaVersion(): void
    {
        $serializer = new OutboxEventSerializer();
        $encoded = [
            'body' => json_encode([
                'schema_version' => 2,
                'source' => 'walleting',
                'message_id' => '0198-future',
                'type' => 'wallet.future.event',
                'deduplication_key' => 'future:1',
                'occurred_at' => '2026-08-07T01:04:00-05:00',
                'correlation_id' => null,
                'causation_id' => null,
                'ledger_transaction_id' => null,
                'provider_event_external_id' => 'evt-future',
                'payload' => [],
            ], JSON_THROW_ON_ERROR),
            'headers' => [],
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported outbox event schema version 2; supported version is 1.');
        $serializer->decode($encoded);
    }

    public function testRetryAndFailureStampsSurviveRoundTrip(): void
    {
        $serializer = new OutboxEventSerializer();
        $event = new OutboxEvent(
            messageId: '0198-stamped',
            type: 'posting.slo.state.changed',
            deduplicationKey: 'posting.slo.state.changed:default:7',
            payload: ['scope' => 'default'],
            ledgerTransactionId: null,
            providerEventExternalId: null,
        );
        $redeliveredAt = new \DateTimeImmutable('2026-08-08T03:20:00-05:00');
        $envelope = new Envelope($event, [
            new RedeliveryStamp(3, $redeliveredAt),
            new DelayStamp(30000),
            new SentToFailureTransportStamp('outbox_events'),
            new ErrorDetailsStamp(\RuntimeException::class, 17, 'notification failed'),
        ]);

        $decoded = $serializer->decode($serializer->encode($envelope));

        $redelivery = $decoded->last(RedeliveryStamp::class);
        self::assertInstanceOf(RedeliveryStamp::class, $redelivery);
        self::assertSame(3, $redelivery->getRetryCount());
        self::assertSame($redeliveredAt->format(DATE_ATOM), $redelivery->getRedeliveredAt()->format(DATE_ATOM));
        self::assertSame(30000, $decoded->last(DelayStamp::class)?->getDelay());
        self::assertSame('outbox_events', $decoded->last(SentToFailureTransportStamp::class)?->getOriginalReceiverName());
        $error = $decoded->last(ErrorDetailsStamp::class);
        self::assertInstanceOf(ErrorDetailsStamp::class, $error);
        self::assertSame(\RuntimeException::class, $error->getExceptionClass());
        self::assertSame(17, $error->getExceptionCode());
        self::assertSame('notification failed', $error->getExceptionMessage());
    }

    public function testDecodedRetryCountStopsRetryStrategyAtConfiguredMaximum(): void
    {
        $serializer = new OutboxEventSerializer();
        $event = new OutboxEvent(
            messageId: '0198-retry-limit',
            type: 'posting.slo.state.changed',
            deduplicationKey: 'posting.slo.state.changed:default:8',
            payload: ['scope' => 'default'],
            ledgerTransactionId: null,
            providerEventExternalId: null,
        );
        $decoded = $serializer->decode($serializer->encode(
            new Envelope($event, [new RedeliveryStamp(3)]),
        ));

        self::assertFalse((new MultiplierRetryStrategy(maxRetries: 3))->isRetryable($decoded));
    }

    public function testMalformedMessengerStampHeaderIsRejectedInsteadOfResettingRetryState(): void
    {
        $serializer = new OutboxEventSerializer();
        $encoded = $serializer->encode(new Envelope(new OutboxEvent(
            messageId: '0198-invalid-stamp',
            type: 'posting.slo.state.changed',
            deduplicationKey: 'posting.slo.state.changed:default:9',
            payload: ['scope' => 'default'],
            ledgerTransactionId: null,
            providerEventExternalId: null,
        )));
        $encoded['headers']['X-Walleting-Messenger-Stamps'] = '{"redelivery":[{"retry_count":"3","redelivered_at":"2026-08-08T03:20:00-05:00"}]}';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Messenger redelivery stamp metadata is invalid.');
        $serializer->decode($encoded);
    }

    public function testDecodeReconstructsOutboxEvent(): void
    {
        $serializer = new OutboxEventSerializer();
        $encoded = [
            'body' => json_encode([
                'schema_version' => 1,
                'source' => 'walleting',
                'message_id' => '0198-decoded',
                'type' => 'provider.event.processed',
                'deduplication_key' => 'provider:evt-1',
                'occurred_at' => '2026-08-07T00:58:00-05:00',
                'correlation_id' => null,
                'causation_id' => 'cause-1',
                'ledger_transaction_id' => null,
                'provider_event_external_id' => 'evt-1',
                'payload' => ['provider' => 'example'],
            ], JSON_THROW_ON_ERROR),
            'headers' => ['Content-Type' => 'application/json'],
        ];

        $message = $serializer->decode($encoded)->getMessage();
        self::assertInstanceOf(OutboxEvent::class, $message);
        self::assertSame('0198-decoded', $message->messageId);
        self::assertSame('provider.event.processed', $message->type);
        self::assertSame('evt-1', $message->providerEventExternalId);
        self::assertSame('cause-1', $message->causationId);
        self::assertSame(['provider' => 'example'], $message->payload);
    }
}
