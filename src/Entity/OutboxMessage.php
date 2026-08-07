<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\OutboxMessageStatus;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'outbox_message')]
#[ORM\Index(name: 'idx_outbox_dispatchable', columns: ['status', 'available_at', 'created_at'])]
#[ORM\UniqueConstraint(name: 'uniq_outbox_deduplication_key', columns: ['deduplication_key'])]
class OutboxMessage
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(name: 'message_type', length: 191)]
    private string $messageType;

    #[ORM\Column(name: 'deduplication_key', length: 191, unique: true)]
    private string $deduplicationKey;

    #[ORM\Column(type: 'json')]
    private array $payload;

    #[ORM\Column(name: 'payload_hash', length: 64)]
    private string $payloadHash;

    #[ORM\ManyToOne(targetEntity: LedgerTransaction::class)]
    #[ORM\JoinColumn(name: 'ledger_transaction_id', nullable: true, onDelete: 'RESTRICT')]
    private ?LedgerTransaction $ledgerTransaction;

    #[ORM\ManyToOne(targetEntity: ProviderEvent::class)]
    #[ORM\JoinColumn(name: 'provider_event_id', nullable: true, onDelete: 'RESTRICT')]
    private ?ProviderEvent $providerEvent;

    #[ORM\Column(enumType: OutboxMessageStatus::class)]
    private OutboxMessageStatus $status;

    #[ORM\Column(name: 'attempt_count', type: 'integer')]
    private int $attemptCount = 0;

    #[ORM\Column(name: 'available_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $availableAt;

    #[ORM\Column(name: 'claimed_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $claimedAt = null;

    #[ORM\Column(name: 'dispatched_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $dispatchedAt = null;

    #[ORM\Column(name: 'last_error', type: 'text', nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $messageType,
        string $deduplicationKey,
        array $payload,
        ?LedgerTransaction $ledgerTransaction = null,
        ?ProviderEvent $providerEvent = null,
        ?\DateTimeImmutable $availableAt = null,
        ?Uuid $id = null,
    ) {
        $messageType = trim($messageType);
        $deduplicationKey = trim($deduplicationKey);
        if ('' === $messageType || '' === $deduplicationKey) {
            throw new \InvalidArgumentException('Outbox message type and deduplication key are required.');
        }
        if (null === $ledgerTransaction && null === $providerEvent) {
            throw new \InvalidArgumentException('Outbox message must reference a ledger transaction or provider event.');
        }

        $this->id = $id ?? Uuid::v7();
        $this->messageType = $messageType;
        $this->deduplicationKey = $deduplicationKey;
        $this->payload = $payload;
        $this->payloadHash = hash('sha256', json_encode($this->normalize($payload), JSON_THROW_ON_ERROR));
        $this->ledgerTransaction = $ledgerTransaction;
        $this->providerEvent = $providerEvent;
        $this->status = OutboxMessageStatus::Pending;
        $this->availableAt = $availableAt ?? new \DateTimeImmutable();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function claim(): void
    {
        if (!in_array($this->status, [OutboxMessageStatus::Pending, OutboxMessageStatus::Failed], true)) {
            throw new \LogicException('Only pending or failed outbox messages can be claimed.');
        }
        if ($this->availableAt > new \DateTimeImmutable()) {
            throw new \LogicException('Outbox message is not available for dispatch yet.');
        }

        $this->status = OutboxMessageStatus::Claimed;
        $this->claimedAt = new \DateTimeImmutable();
        ++$this->attemptCount;
    }

    public function markDispatched(): void
    {
        if (OutboxMessageStatus::Claimed !== $this->status) {
            throw new \LogicException('Only claimed outbox messages can be dispatched.');
        }
        $this->status = OutboxMessageStatus::Dispatched;
        $this->dispatchedAt = new \DateTimeImmutable();
        $this->lastError = null;
    }

    public function markFailed(string $error, \DateTimeImmutable $availableAt): void
    {
        $this->assertClaimedFailure($error);
        $this->status = OutboxMessageStatus::Failed;
        $this->lastError = trim($error);
        $this->availableAt = $availableAt;
    }

    public function markDead(string $error): void
    {
        $this->assertClaimedFailure($error);
        $this->status = OutboxMessageStatus::Dead;
        $this->lastError = trim($error);
    }

    private function assertClaimedFailure(string $error): void
    {
        if (OutboxMessageStatus::Claimed !== $this->status) {
            throw new \LogicException('Only claimed outbox messages can fail.');
        }
        if ('' === trim($error)) {
            throw new \InvalidArgumentException('Outbox failure error is required.');
        }
    }

    private function normalize(array $value): array
    {
        ksort($value);
        foreach ($value as &$item) {
            if (is_array($item)) { $item = $this->normalize($item); }
        }
        unset($item);

        return $value;
    }

    public function id(): Uuid { return $this->id; }
    public function messageType(): string { return $this->messageType; }
    public function deduplicationKey(): string { return $this->deduplicationKey; }
    public function payload(): array { return $this->payload; }
    public function payloadHash(): string { return $this->payloadHash; }
    public function ledgerTransaction(): ?LedgerTransaction { return $this->ledgerTransaction; }
    public function providerEvent(): ?ProviderEvent { return $this->providerEvent; }
    public function status(): OutboxMessageStatus { return $this->status; }
    public function attemptCount(): int { return $this->attemptCount; }
    public function availableAt(): \DateTimeImmutable { return $this->availableAt; }
    public function createdAt(): \DateTimeImmutable { return $this->createdAt; }
    public function lastError(): ?string { return $this->lastError; }
}
