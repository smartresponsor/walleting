<?php

declare(strict_types=1);

namespace App\Walleting\Entity;

use App\Walleting\Enum\ReconciliationRunStatus;
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
    #[ORM\Column(name: 'checked_count', type: 'integer')]
    private int $checkedCount = 0;
    #[ORM\Column(name: 'matched_count', type: 'integer')]
    private int $matchedCount = 0;
    #[ORM\Column(name: 'mismatch_count', type: 'integer')]
    private int $mismatchCount = 0;

    public function __construct(string $provider, string $runKey, ?Uuid $id = null)
    {
        $provider = trim($provider); $runKey = trim($runKey);
        if ('' === $provider || '' === $runKey) { throw new \InvalidArgumentException('Provider and run key are required.'); }
        $this->id = $id ?? Uuid::v7(); $this->provider = $provider; $this->runKey = $runKey; $this->status = ReconciliationRunStatus::Pending; $this->createdAt = new \DateTimeImmutable();
    }

    public function start(): void { if (ReconciliationRunStatus::Pending !== $this->status) { throw new \LogicException('Only pending reconciliation can start.'); } $this->status = ReconciliationRunStatus::Running; $this->startedAt = new \DateTimeImmutable(); }
    public function recordMatch(): void { $this->assertRunning(); ++$this->checkedCount; ++$this->matchedCount; }
    public function recordMismatch(): void { $this->assertRunning(); ++$this->checkedCount; ++$this->mismatchCount; }
    public function complete(): void { $this->assertRunning(); $this->status = ReconciliationRunStatus::Completed; $this->completedAt = new \DateTimeImmutable(); }
    public function fail(string $message): void { if (!in_array($this->status, [ReconciliationRunStatus::Pending, ReconciliationRunStatus::Running], true)) { throw new \LogicException('Reconciliation cannot fail from its current status.'); } $message = trim($message); if ('' === $message) { throw new \InvalidArgumentException('Failure message is required.'); } $this->status = ReconciliationRunStatus::Failed; $this->failureMessage = $message; $this->completedAt = new \DateTimeImmutable(); }
    private function assertRunning(): void { if (ReconciliationRunStatus::Running !== $this->status) { throw new \LogicException('Reconciliation counters can only change while running.'); } }
    public function status(): ReconciliationRunStatus { return $this->status; }
    public function checkedCount(): int { return $this->checkedCount; }
    public function matchedCount(): int { return $this->matchedCount; }
    public function mismatchCount(): int { return $this->mismatchCount; }
    public function provider(): string { return $this->provider; }
    public function runKey(): string { return $this->runKey; }
}
