<?php

declare(strict_types=1);

namespace App\Walleting\Entity;

use App\Walleting\Enum\WalletReconciliationMismatchStatus;
use App\Walleting\Enum\WalletReconciliationMismatchType;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'reconciliation_mismatch')]
#[ORM\UniqueConstraint(name: 'uniq_reconciliation_mismatch_reference', columns: ['run_id', 'external_reference', 'type'])]
class WalletReconciliationMismatch
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;
    #[ORM\ManyToOne(targetEntity: WalletReconciliationRun::class)]
    #[ORM\JoinColumn(name: 'run_id', nullable: false, onDelete: 'RESTRICT')]
    private WalletReconciliationRun $run;
    #[ORM\Column(enumType: WalletReconciliationMismatchType::class)]
    private WalletReconciliationMismatchType $type;
    #[ORM\Column(name: 'external_reference', length: 191)]
    private string $externalReference;
    #[ORM\Column(type: 'json')]
    private array $details;
    #[ORM\Column(enumType: WalletReconciliationMismatchStatus::class)]
    private WalletReconciliationMismatchStatus $status;
    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;
    #[ORM\Column(name: 'resolved_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    public function __construct(WalletReconciliationRun $run, WalletReconciliationMismatchType $type, string $externalReference, array $details = [], ?Uuid $id = null)
    {
        $externalReference = trim($externalReference);
        if ('' === $externalReference) {
            throw new \InvalidArgumentException('External reference is required.');
        }
        $this->id = $id ?? Uuid::v7();
        $this->run = $run;
        $this->type = $type;
        $this->externalReference = $externalReference;
        $this->details = $details;
        $this->status = WalletReconciliationMismatchStatus::Open;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function resolve(): void
    {
        $this->close(WalletReconciliationMismatchStatus::Resolved);
    }

    public function ignore(): void
    {
        $this->close(WalletReconciliationMismatchStatus::Ignored);
    }

    private function close(WalletReconciliationMismatchStatus $status): void
    {
        if (WalletReconciliationMismatchStatus::Open !== $this->status) {
            throw new \LogicException('Only open mismatch can be closed.');
        } $this->status = $status;
        $this->resolvedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function status(): WalletReconciliationMismatchStatus
    {
        return $this->status;
    }

    public function type(): WalletReconciliationMismatchType
    {
        return $this->type;
    }

    public function externalReference(): string
    {
        return $this->externalReference;
    }
}
