<?php

declare(strict_types=1);

namespace App\Walleting\Entity;

use App\Walleting\Enum\ReconciliationMismatchStatus;
use App\Walleting\Enum\ReconciliationMismatchType;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'reconciliation_mismatch')]
#[ORM\UniqueConstraint(name: 'uniq_reconciliation_mismatch_reference', columns: ['run_id', 'external_reference', 'type'])]
class ReconciliationMismatch
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;
    #[ORM\ManyToOne(targetEntity: ReconciliationRun::class)]
    #[ORM\JoinColumn(name: 'run_id', nullable: false, onDelete: 'RESTRICT')]
    private ReconciliationRun $run;
    #[ORM\Column(enumType: ReconciliationMismatchType::class)]
    private ReconciliationMismatchType $type;
    #[ORM\Column(name: 'external_reference', length: 191)]
    private string $externalReference;
    #[ORM\Column(type: 'json')]
    private array $details;
    #[ORM\Column(enumType: ReconciliationMismatchStatus::class)]
    private ReconciliationMismatchStatus $status;
    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;
    #[ORM\Column(name: 'resolved_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    public function __construct(ReconciliationRun $run, ReconciliationMismatchType $type, string $externalReference, array $details = [], ?Uuid $id = null)
    {
        $externalReference = trim($externalReference);
        if ('' === $externalReference) { throw new \InvalidArgumentException('External reference is required.'); }
        $this->id = $id ?? Uuid::v7(); $this->run = $run; $this->type = $type; $this->externalReference = $externalReference; $this->details = $details; $this->status = ReconciliationMismatchStatus::Open; $this->createdAt = new \DateTimeImmutable();
    }

    public function resolve(): void { $this->close(ReconciliationMismatchStatus::Resolved); }
    public function ignore(): void { $this->close(ReconciliationMismatchStatus::Ignored); }
    private function close(ReconciliationMismatchStatus $status): void { if (ReconciliationMismatchStatus::Open !== $this->status) { throw new \LogicException('Only open mismatch can be closed.'); } $this->status = $status; $this->resolvedAt = new \DateTimeImmutable(); }
    public function status(): ReconciliationMismatchStatus { return $this->status; }
    public function type(): ReconciliationMismatchType { return $this->type; }
    public function externalReference(): string { return $this->externalReference; }
}
