<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\TransactionType;
use App\Objecting\EntityInterface\ObjectRelationEntityInterface;
use App\Objecting\EntityTrait\Embeddable\ObjectAuditEmbeddableTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'financial_operation_link')]
#[ORM\UniqueConstraint(name: 'uniq_financial_operation_inverse_source', columns: ['source_transaction_id'], options: ['where' => "operation_type IN ('refund', 'reverse')"])]
#[ORM\UniqueConstraint(name: 'uniq_financial_operation_reservation_settlement', columns: ['reservation_id'], options: ['where' => "reservation_id IS NOT NULL AND operation_type IN ('capture', 'release')"])]
#[ORM\UniqueConstraint(name: 'uniq_financial_operation_result', columns: ['result_transaction_id'])]
class FinancialOperationLink implements ObjectRelationEntityInterface
{
    use ObjectAuditEmbeddableTrait;
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
        if ($resultTransaction->type() !== $operationType) {
            throw new \InvalidArgumentException('Linked result transaction type must match the operation type.');
        }
        if (in_array($operationType, [TransactionType::Capture, TransactionType::Release], true) && TransactionType::Reserve !== $sourceTransaction->type()) {
            throw new \InvalidArgumentException('Capture and release links must originate from a reserve transaction.');
        }
        if (in_array($operationType, [TransactionType::Refund, TransactionType::Reverse], true) && in_array($sourceTransaction->type(), [TransactionType::Refund, TransactionType::Reverse], true)) {
            throw new \InvalidArgumentException('Refund and reverse cannot originate from an inverse transaction.');
        }
        if (null !== $reservation && $reservation->reserveTransaction() !== $sourceTransaction) {
            throw new \InvalidArgumentException('Reservation source transaction does not match.');
        }

        $this->id = $id ?? Uuid::v7();
        $this->operationType = $operationType;
        $this->sourceTransaction = $sourceTransaction;
        $this->resultTransaction = $resultTransaction;
        $this->reservation = $reservation;
        $this->initializeObjectAudit();
    }

    public function operationType(): TransactionType { return $this->operationType; }
    public function sourceTransaction(): LedgerTransaction { return $this->sourceTransaction; }
    public function resultTransaction(): LedgerTransaction { return $this->resultTransaction; }
    public function reservation(): ?Reservation { return $this->reservation; }
}
