<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ReconciliationRunStatus;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'reconciliation_run')]
#[ORM\UniqueConstraint(name: 'uniq_reconciliation_run_key', columns: ['provider', 'run_key'])]
class ReconciliationRun
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;
    #[ORM\Column(length: 64)]
    private string $provider;
    #[ORM\Column(name: 'run_key', length: 128)]
    private string $runKey;
    #[ORM\Column(enumType: ReconciliationRunStatus::class)]
    private ReconciliationRunStatus $status;
    #[ORM\Column(name: 'started_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;
    #[ORM\Column(name: 'completed_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;
    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;
    #[ORM\Column(name: 'failure_message', type: 'text', nullable: true)]
    private ?string $failureMessage = null;

    public function __construct(string $provider, string $runKey, ?Uuid $id = null)
    {
        $provider = trim($provider); $runKey = trim($runKey);
        if ('' === $provider || '' === $runKey) { throw new \InvalidArgumentException('Provider and run key are required.'); }
        $this->id = $id ?? Uuid::v7(); $this->provider = $provider; $this->runKey = $runKey; $this->status = ReconciliationRunStatus::Pending; $this->createdAt = new \DateTimeImmutable();
    }

    public function start(): void { if (ReconciliationRunStatus::Pending !== $this->status) { throw new \LogicException('Only pending reconciliation can start.'); } $this->status = ReconciliationRunStatus::Running; $this->startedAt = new \DateTimeImmutable(); }
    public function complete(): void { if (ReconciliationRunStatus::Running !== $this->status) { throw new \LogicException('Only running reconciliation can complete.'); } $this->status = ReconciliationRunStatus::Completed; $this->completedAt = new \DateTimeImmutable(); }
    public function fail(string $message): void { if (!in_array($this->status, [ReconciliationRunStatus::Pending, ReconciliationRunStatus::Running], true)) { throw new \LogicException('Reconciliation cannot fail from its current status.'); } $message = trim($message); if ('' === $message) { throw new \InvalidArgumentException('Failure message is required.'); } $this->status = ReconciliationRunStatus::Failed; $this->failureMessage = $message; $this->completedAt = new \DateTimeImmutable(); }
    public function status(): ReconciliationRunStatus { return $this->status; }
    public function provider(): string { return $this->provider; }
    public function runKey(): string { return $this->runKey; }
}
