<?php

declare(strict_types=1);

namespace App\Walleting\Handler\Outbox;

use App\Walleting\Entity\WalletOutboxMessage;
use App\Walleting\Event\Outbox\WalletOutboxEvent;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class WalletMessengerOutboxMessageHandler implements WalletOutboxMessageHandlerInterface
{
    public function __construct(private MessageBusInterface $messageBus)
    {
    }

    public function supports(string $messageType): bool
    {
        return '' !== trim($messageType);
    }

    public function handle(WalletOutboxMessage $message): void
    {
        $payload = $message->payload();
        $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];

        $this->messageBus->dispatch(new WalletOutboxEvent(
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
