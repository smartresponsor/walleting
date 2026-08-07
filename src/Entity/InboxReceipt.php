<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\InboxReceiptStatus;
use App\Message\OutboxEvent;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'inbox_receipt')]
#[ORM\UniqueConstraint(name: 'uniq_inbox_receipt_source_message', columns: ['source', 'message_id'])]
#[ORM\Index(name: 'idx_inbox_receipt_status_received', columns: ['status', 'received_at'])]
class InboxReceipt
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 64)]
    private string $source;

    #[ORM\Column(name: 'message_id', length: 191)]
    private string $messageId;

    #[ORM\Column(name: 'schema_version', type: 'integer')]
    private int $schemaVersion;

    #[ORM\Column(name: 'event_type', length: 191)]
    private string $eventType;

    #[ORM\Column(name: 'deduplication_key', length: 191)]
    private string $deduplicationKey;

    #[ORM\Column(name: 'payload_hash', length: 64)]
    private string $payloadHash;

    #[ORM\Column(enumType: InboxReceiptStatus::class)]
    private InboxReceiptStatus $status;

    #[ORM\Column(name: 'received_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $receivedAt;

    #[ORM\Column(name: 'processed_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $processedAt = null;

    public function __construct(OutboxEvent $event, ?Uuid $id = null)
    {
        $this->id = $id ?? Uuid::v7();
        $this->source = $this->required($event->source, 'source');
        $this->messageId = $this->required($event->messageId, 'message id');
        $this->schemaVersion = $event->schemaVersion;
        $this->eventType = $this->required($event->type, 'event type');
        $this->deduplicationKey = $this->required($event->deduplicationKey, 'deduplication key');
        $this->payloadHash = hash('sha256', json_encode($this->normalize($event->payload), JSON_THROW_ON_ERROR));
        $this->status = InboxReceiptStatus::Processing;
        $this->receivedAt = new \DateTimeImmutable();
    }

    public function assertSameEvent(OutboxEvent $event): void
    {
        $payloadHash = hash('sha256', json_encode($this->normalize($event->payload), JSON_THROW_ON_ERROR));
        if (
            $this->source !== $event->source
            || $this->messageId !== $event->messageId
            || $this->schemaVersion !== $event->schemaVersion
            || $this->eventType !== $event->type
            || $this->deduplicationKey !== $event->deduplicationKey
            || !hash_equals($this->payloadHash, $payloadHash)
        ) {
            throw new \DomainException('Inbox receipt identity is already bound to different event content.');
        }
    }

    public function markProcessed(): void
    {
        if (InboxReceiptStatus::Processed === $this->status) {
            return;
        }

        $this->status = InboxReceiptStatus::Processed;
        $this->processedAt = new \DateTimeImmutable();
    }

    public function isProcessed(): bool
    {
        return InboxReceiptStatus::Processed === $this->status;
    }

    private function required(string $value, string $label): string
    {
        $value = trim($value);
        if ('' === $value) {
            throw new \InvalidArgumentException(sprintf('Inbox receipt %s is required.', $label));
        }

        return $value;
    }

    private function normalize(array $value): array
    {
        ksort($value);
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = $this->normalize($item);
            }
        }
        unset($item);

        return $value;
    }

    public function id(): Uuid { return $this->id; }
    public function source(): string { return $this->source; }
    public function messageId(): string { return $this->messageId; }
    public function schemaVersion(): int { return $this->schemaVersion; }
    public function eventType(): string { return $this->eventType; }
    public function deduplicationKey(): string { return $this->deduplicationKey; }
    public function payloadHash(): string { return $this->payloadHash; }
    public function status(): InboxReceiptStatus { return $this->status; }
    public function processedAt(): ?\DateTimeImmutable { return $this->processedAt; }
}
