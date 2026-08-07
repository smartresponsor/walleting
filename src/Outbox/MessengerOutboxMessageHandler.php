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
        $this->messageBus->dispatch(new OutboxEvent(
            $message->id()->toRfc4122(),
            $message->messageType(),
            $message->deduplicationKey(),
            $message->payload(),
            $message->ledgerTransaction()?->id()->toRfc4122(),
            $message->providerEvent()?->externalId(),
        ));
    }
}
