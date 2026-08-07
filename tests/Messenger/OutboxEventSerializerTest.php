<?php

declare(strict_types=1);

namespace App\Tests\Messenger;

use App\Message\OutboxEvent;
use App\Messenger\OutboxEventSerializer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;

final class OutboxEventSerializerTest extends TestCase
{
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
