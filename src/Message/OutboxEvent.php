<?php

declare(strict_types=1);

namespace App\Message;

final readonly class OutboxEvent
{
    public function __construct(
        public string $messageId,
        public string $type,
        public string $deduplicationKey,
        public array $payload,
        public ?string $ledgerTransactionId,
        public ?string $providerEventExternalId,
    ) {
        if ('' === trim($messageId) || '' === trim($type) || '' === trim($deduplicationKey)) {
            throw new \InvalidArgumentException('Outbox event identity fields are required.');
        }
    }
}
