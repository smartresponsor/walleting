<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\TransactionType;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'financial_operation_link')]
#[ORM\UniqueConstraint(name: 'uniq_financial_operation_source_type', columns: ['source_transaction_id', 'operation_type'])]
#[ORM\UniqueConstraint(name: 'uniq_financial_operation_reservation_type', columns: ['reservation_id', 'operation_type'])]
#[ORM\UniqueConstraint(name: 'uniq_financial_operation_result', columns: ['result_transaction_id'])]
class FinancialOperationLink
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(name: 'operation_type', enumType: TransactionType::class)]
    private TransactionType $operationType;

    #[ORM\ManyToOne(targetEntity: LedgerTransaction::class)]
    #[ORM\JoinColumn(name: 'source_transaction_id', nullable: false, onDelete: 'RESTRICT')]
    private LedgerTransaction $sourceTransaction;

    #[ORM\OneToOne(targetEntity: LedgerTransaction::class)]
    #[ORM\JoinColumn(name: 'result_transaction_id', nullable: false, unique: true, onDelete: 'RESTRICT')]
    private LedgerTransaction $resultTransaction;

    #[ORM\ManyToOne(targetEntity: Reservation::class)]
    #[ORM\JoinColumn(name: 'reservation_id', nullable: true, onDelete: 'RESTRICT')]
    private ?Reservation $reservation;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(TransactionType $operationType, LedgerTransaction $sourceTransaction, LedgerTransaction $resultTransaction, ?Reservation $reservation = null, ?Uuid $id = null)
    {
        if (!in_array($operationType, [TransactionType::Capture, TransactionType::Release, TransactionType::Refund, TransactionType::Reverse], true)) {
            throw new \InvalidArgumentException('Unsupported linked financial operation type.');
        }
        if (in_array($operationType, [TransactionType::Capture, TransactionType::Release], true) && null === $reservation) {
            throw new \InvalidArgumentException('Capture and release links require a reservation.');
        }
        if (in_array($operationType, [TransactionType::Refund, TransactionType::Reverse], true) && null !== $reservation) {
            throw new \InvalidArgumentException('Refund and reverse links cannot reference a reservation.');
        }
        if (null !== $reservation && $reservation->reserveTransaction() !== $sourceTransaction) {
            throw new \InvalidArgumentException('Reservation source transaction does not match.');
        }

        $this->id = $id ?? Uuid::v7();
        $this->operationType = $operationType;
        $this->sourceTransaction = $sourceTransaction;
        $this->resultTransaction = $resultTransaction;
        $this->reservation = $reservation;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function operationType(): TransactionType { return $this->operationType; }
    public function sourceTransaction(): LedgerTransaction { return $this->sourceTransaction; }
    public function resultTransaction(): LedgerTransaction { return $this->resultTransaction; }
    public function reservation(): ?Reservation { return $this->reservation; }
}
