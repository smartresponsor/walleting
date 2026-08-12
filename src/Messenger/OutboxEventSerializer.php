<?php

declare(strict_types=1);

namespace App\Walleting\Messenger;

use App\Walleting\Message\OutboxEvent;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
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

        $schemaVersion = $this->requiredInt($data, 'schema_version');
        if (OutboxEvent::SCHEMA_VERSION !== $schemaVersion) {
            throw new \InvalidArgumentException(sprintf('Unsupported outbox event schema version %d; supported version is %d.', $schemaVersion, OutboxEvent::SCHEMA_VERSION));
        }

        $event = new OutboxEvent(
            messageId: $this->requiredString($data, 'message_id'),
            type: $this->requiredString($data, 'type'),
            deduplicationKey: $this->requiredString($data, 'deduplication_key'),
            payload: $this->requiredArray($data, 'payload'),
            ledgerTransactionId: $this->nullableString($data, 'ledger_transaction_id'),
            providerEventExternalId: $this->nullableString($data, 'provider_event_external_id'),
            schemaVersion: $schemaVersion,
            source: $this->requiredString($data, 'source'),
            occurredAt: $this->nullableString($data, 'occurred_at'),
            correlationId: $this->nullableString($data, 'correlation_id'),
            causationId: $this->nullableString($data, 'causation_id'),
        );

        return new Envelope($event, $this->decodeMessengerStamps($encodedEnvelope['headers'] ?? []));
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
                ...$this->encodeMessengerStamps($envelope),
            ],
        ];
    }

    /** @return array<string, string> */
    private function encodeMessengerStamps(Envelope $envelope): array
    {
        $data = [];

        $redeliveries = array_map(
            static fn (RedeliveryStamp $stamp): array => [
                'retry_count' => $stamp->getRetryCount(),
                'redelivered_at' => $stamp->getRedeliveredAt()->format(DATE_ATOM),
            ],
            $envelope->all(RedeliveryStamp::class),
        );
        if ([] !== $redeliveries) {
            $data['redelivery'] = $redeliveries;
        }

        $delays = array_map(
            static fn (DelayStamp $stamp): int => $stamp->getDelay(),
            $envelope->all(DelayStamp::class),
        );
        if ([] !== $delays) {
            $data['delay'] = $delays;
        }

        $failureTransports = array_map(
            static fn (SentToFailureTransportStamp $stamp): string => $stamp->getOriginalReceiverName(),
            $envelope->all(SentToFailureTransportStamp::class),
        );
        if ([] !== $failureTransports) {
            $data['failure_transport'] = $failureTransports;
        }

        $errors = array_map(
            static fn (ErrorDetailsStamp $stamp): array => [
                'exception_class' => $stamp->getExceptionClass(),
                'exception_code' => $stamp->getExceptionCode(),
                'exception_message' => $stamp->getExceptionMessage(),
            ],
            $envelope->all(ErrorDetailsStamp::class),
        );
        if ([] !== $errors) {
            $data['error_details'] = $errors;
        }

        if ([] === $data) {
            return [];
        }

        return ['X-Walleting-Messenger-Stamps' => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)];
    }

    /** @param array<string, mixed> $headers @return list<object> */
    private function decodeMessengerStamps(array $headers): array
    {
        $encoded = $headers['X-Walleting-Messenger-Stamps'] ?? null;
        if (null === $encoded) {
            return [];
        }
        if (!is_string($encoded) || '' === trim($encoded)) {
            throw new \InvalidArgumentException('Messenger stamp transport header must be a non-empty JSON string.');
        }

        $data = json_decode($encoded, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \InvalidArgumentException('Messenger stamp transport header must decode to an object.');
        }

        $stamps = [];
        foreach ($this->stampList($data, 'redelivery') as $item) {
            if (!is_array($item) || !is_int($item['retry_count'] ?? null) || ($item['retry_count'] ?? -1) < 0 || !is_string($item['redelivered_at'] ?? null)) {
                throw new \InvalidArgumentException('Messenger redelivery stamp metadata is invalid.');
            }
            try {
                $redeliveredAt = new \DateTimeImmutable($item['redelivered_at']);
            } catch (\Throwable $exception) {
                throw new \InvalidArgumentException('Messenger redelivery timestamp is invalid.', 0, $exception);
            }
            $stamps[] = new RedeliveryStamp($item['retry_count'], $redeliveredAt);
        }

        foreach ($this->stampList($data, 'delay') as $delay) {
            if (!is_int($delay) || $delay < 0) {
                throw new \InvalidArgumentException('Messenger delay stamp metadata is invalid.');
            }
            $stamps[] = new DelayStamp($delay);
        }

        foreach ($this->stampList($data, 'failure_transport') as $receiverName) {
            if (!is_string($receiverName) || '' === trim($receiverName)) {
                throw new \InvalidArgumentException('Messenger failure transport stamp metadata is invalid.');
            }
            $stamps[] = new SentToFailureTransportStamp($receiverName);
        }

        foreach ($this->stampList($data, 'error_details') as $item) {
            if (!is_array($item) || !is_string($item['exception_class'] ?? null) || '' === trim($item['exception_class']) || (!is_int($item['exception_code'] ?? null) && !is_string($item['exception_code'] ?? null)) || !is_string($item['exception_message'] ?? null)) {
                throw new \InvalidArgumentException('Messenger error details stamp metadata is invalid.');
            }
            $stamps[] = new ErrorDetailsStamp(
                $item['exception_class'],
                $item['exception_code'],
                $item['exception_message'],
            );
        }

        return $stamps;
    }

    /** @param array<string, mixed> $data @return list<mixed> */
    private function stampList(array $data, string $key): array
    {
        $value = $data[$key] ?? [];
        if (!is_array($value) || !array_is_list($value)) {
            throw new \InvalidArgumentException(sprintf('Messenger stamp field "%s" must be a list.', $key));
        }

        return $value;
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
