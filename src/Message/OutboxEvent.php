<?php

declare(strict_types=1);

namespace App\Walleting\Message;

final readonly class OutboxEvent
{
    public const int SCHEMA_VERSION = 1;
    public const string SOURCE = 'walleting';

    public function __construct(
        public string $messageId,
        public string $type,
        public string $deduplicationKey,
        public array $payload,
        public ?string $ledgerTransactionId,
        public ?string $providerEventExternalId,
        public int $schemaVersion = self::SCHEMA_VERSION,
        public string $source = self::SOURCE,
        public ?string $occurredAt = null,
        public ?string $correlationId = null,
        public ?string $causationId = null,
    ) {
        if ('' === trim($messageId) || '' === trim($type) || '' === trim($deduplicationKey)) {
            throw new \InvalidArgumentException('Outbox event identity fields are required.');
        }
        if ($schemaVersion < 1 || '' === trim($source)) {
            throw new \InvalidArgumentException('Outbox event schema version and source are required.');
        }
    }
}
