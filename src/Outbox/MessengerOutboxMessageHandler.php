<?php

declare(strict_types=1);

namespace App\Outbox;

use App\Entity\OutboxMessage;
use App\Message\OutboxEvent;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class MessengerOutboxMessageHandler implements OutboxMessageHandlerInterface
{
    public function __construct(private MessageBusInterface $messageBus)
    {
    }

    public function supports(string $messageType): bool
    {
        return '' !== trim($messageType);
    }

    public function handle(OutboxMessage $message): void
    {
        $payload = $message->payload();
        $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];

        $this->messageBus->dispatch(new OutboxEvent(
            $message->id()->toRfc4122(),
            $message->messageType(),
            $message->deduplicationKey(),
            $payload,
            $message->ledgerTransaction()?->id()->toRfc4122(),
            $message->providerEvent()?->externalId(),
            occurredAt: $message->createdAt()->format(DATE_ATOM),
            correlationId: $this->metadataString($metadata, 'correlation_id'),
            causationId: $this->metadataString($metadata, 'causation_id'),
        ));
    }

    /** @param array<string, mixed> $metadata */
    private function metadataString(array $metadata, string $key): ?string
    {
        $value = $metadata[$key] ?? null;
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return '' === $value ? null : $value;
    }
}
