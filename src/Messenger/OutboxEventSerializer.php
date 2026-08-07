<?php

declare(strict_types=1);

namespace App\Messenger;

use App\Message\OutboxEvent;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

final class OutboxEventSerializer implements SerializerInterface
{
    public function decode(array $encodedEnvelope): Envelope
    {
        $body = $encodedEnvelope['body'] ?? null;
        if (!is_string($body) || '' === trim($body)) {
            throw new \InvalidArgumentException('Encoded outbox envelope body is required.');
        }

        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \InvalidArgumentException('Encoded outbox envelope body must decode to an object.');
        }

        $event = new OutboxEvent(
            messageId: $this->requiredString($data, 'message_id'),
            type: $this->requiredString($data, 'type'),
            deduplicationKey: $this->requiredString($data, 'deduplication_key'),
            payload: $this->requiredArray($data, 'payload'),
            ledgerTransactionId: $this->nullableString($data, 'ledger_transaction_id'),
            providerEventExternalId: $this->nullableString($data, 'provider_event_external_id'),
            schemaVersion: $this->requiredInt($data, 'schema_version'),
            source: $this->requiredString($data, 'source'),
            occurredAt: $this->nullableString($data, 'occurred_at'),
            correlationId: $this->nullableString($data, 'correlation_id'),
            causationId: $this->nullableString($data, 'causation_id'),
        );

        return new Envelope($event);
    }

    public function encode(Envelope $envelope): array
    {
        $message = $envelope->getMessage();
        if (!$message instanceof OutboxEvent) {
            throw new \InvalidArgumentException(sprintf('OutboxEventSerializer cannot encode %s.', $message::class));
        }

        return [
            'body' => json_encode([
                'schema_version' => $message->schemaVersion,
                'source' => $message->source,
                'message_id' => $message->messageId,
                'type' => $message->type,
                'deduplication_key' => $message->deduplicationKey,
                'occurred_at' => $message->occurredAt,
                'correlation_id' => $message->correlationId,
                'causation_id' => $message->causationId,
                'ledger_transaction_id' => $message->ledgerTransactionId,
                'provider_event_external_id' => $message->providerEventExternalId,
                'payload' => $message->payload,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Walleting-Event-Schema' => (string) $message->schemaVersion,
                'X-Walleting-Event-Type' => $message->type,
            ],
        ];
    }

    /** @param array<string, mixed> $data */
    private function requiredString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || '' === trim($value)) {
            throw new \InvalidArgumentException(sprintf('Outbox event field "%s" must be a non-empty string.', $key));
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private function nullableString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;
        if (null === $value) {
            return null;
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException(sprintf('Outbox event field "%s" must be a string or null.', $key));
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private function requiredArray(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value)) {
            throw new \InvalidArgumentException(sprintf('Outbox event field "%s" must be an object or array.', $key));
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private function requiredInt(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        if (!is_int($value)) {
            throw new \InvalidArgumentException(sprintf('Outbox event field "%s" must be an integer.', $key));
        }

        return $value;
    }
}
