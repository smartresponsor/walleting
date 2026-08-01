<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ProviderEventStatus;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'provider_event')]
#[ORM\UniqueConstraint(name: 'uniq_provider_event_external', columns: ['provider', 'external_id'])]
class ProviderEvent
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;
    #[ORM\Column(length: 64)]
    private string $provider;
    #[ORM\Column(name: 'external_id', length: 191)]
    private string $externalId;
    #[ORM\Column(name: 'event_type', length: 128)]
    private string $eventType;
    #[ORM\Column(type: 'json')]
    private array $payload;
    #[ORM\Column(enumType: ProviderEventStatus::class)]
    private ProviderEventStatus $status;
    #[ORM\Column(name: 'received_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $receivedAt;
    #[ORM\Column(name: 'processed_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $processedAt = null;
    #[ORM\Column(name: 'failure_message', type: 'text', nullable: true)]
    private ?string $failureMessage = null;

    public function __construct(string $provider, string $externalId, string $eventType, array $payload, ?Uuid $id = null)
    {
        $provider = trim($provider); $externalId = trim($externalId); $eventType = trim($eventType);
        if ('' === $provider || '' === $externalId || '' === $eventType) { throw new \InvalidArgumentException('Provider, external id, and event type are required.'); }
        $this->id = $id ?? Uuid::v7(); $this->provider = $provider; $this->externalId = $externalId; $this->eventType = $eventType; $this->payload = $payload; $this->status = ProviderEventStatus::Received; $this->receivedAt = new \DateTimeImmutable();
    }

    public function markProcessed(): void { if (ProviderEventStatus::Received !== $this->status) { throw new \LogicException('Only received events can be processed.'); } $this->status = ProviderEventStatus::Processed; $this->processedAt = new \DateTimeImmutable(); }
    public function markFailed(string $message): void { if (ProviderEventStatus::Received !== $this->status) { throw new \LogicException('Only received events can fail.'); } $message = trim($message); if ('' === $message) { throw new \InvalidArgumentException('Failure message is required.'); } $this->status = ProviderEventStatus::Failed; $this->failureMessage = $message; }
    public function status(): ProviderEventStatus { return $this->status; }
    public function provider(): string { return $this->provider; }
    public function externalId(): string { return $this->externalId; }
    public function payload(): array { return $this->payload; }
}
