<?php

declare(strict_types=1);

namespace App\Walleting\Entity;

use App\Walleting\Enum\WithdrawalStatus;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'withdrawal')]
#[ORM\UniqueConstraint(name: 'uniq_withdrawal_idempotency_key', columns: ['idempotency_key'])]
class Withdrawal
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Wallet::class)]
    #[ORM\JoinColumn(name: 'wallet_id', nullable: false, onDelete: 'RESTRICT')]
    private Wallet $wallet;

    #[ORM\ManyToOne(targetEntity: PaymentInstrument::class)]
    #[ORM\JoinColumn(name: 'payment_instrument_id', nullable: false, onDelete: 'RESTRICT')]
    private PaymentInstrument $paymentInstrument;

    #[ORM\ManyToOne(targetEntity: LedgerTransaction::class)]
    #[ORM\JoinColumn(name: 'transaction_id', nullable: true, onDelete: 'RESTRICT')]
    private ?LedgerTransaction $transaction = null;

    #[ORM\OneToOne(targetEntity: LedgerTransaction::class)]
    #[ORM\JoinColumn(name: 'reversal_transaction_id', nullable: true, unique: true, onDelete: 'RESTRICT')]
    private ?LedgerTransaction $reversalTransaction = null;

    #[ORM\Column(name: 'amount_minor', type: 'bigint')]
    private int $amountMinor;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(name: 'idempotency_key', length: 128, unique: true)]
    private string $idempotencyKey;

    #[ORM\Column(enumType: WithdrawalStatus::class)]
    private WithdrawalStatus $status;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(Wallet $wallet, PaymentInstrument $paymentInstrument, int $amountMinor, string $currency, string $idempotencyKey, ?Uuid $id = null)
    {
        $currency = strtoupper(trim($currency));
        $idempotencyKey = trim($idempotencyKey);
        if ($amountMinor <= 0 || 1 !== preg_match('/^[A-Z]{3}$/', $currency) || '' === $idempotencyKey) {
            throw new \InvalidArgumentException('Withdrawal amount, currency, and idempotency key are required.');
        }
        if ($paymentInstrument->wallet() !== $wallet) {
            throw new \InvalidArgumentException('Withdrawal instrument must belong to the wallet.');
        }

        $this->id = $id ?? Uuid::v7();
        $this->wallet = $wallet;
        $this->paymentInstrument = $paymentInstrument;
        $this->amountMinor = $amountMinor;
        $this->currency = $currency;
        $this->idempotencyKey = $idempotencyKey;
        $this->status = WithdrawalStatus::Pending;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function start(): void
    {
        if (WithdrawalStatus::Pending !== $this->status) {
            throw new \LogicException('Only pending withdrawal can start.');
        }
        $this->status = WithdrawalStatus::Processing;
    }

    public function succeed(LedgerTransaction $transaction): void
    {
        if (WithdrawalStatus::Processing !== $this->status) {
            throw new \LogicException('Only processing withdrawal can succeed.');
        }
        $this->transaction = $transaction;
        $this->status = WithdrawalStatus::Succeeded;
    }

    public function fail(): void
    {
        if (!in_array($this->status, [WithdrawalStatus::Pending, WithdrawalStatus::Processing], true)) {
            throw new \LogicException('Withdrawal cannot fail from its current status.');
        }
        $this->status = WithdrawalStatus::Failed;
    }

    public function reverse(LedgerTransaction $transaction): void
    {
        if (WithdrawalStatus::Succeeded !== $this->status) {
            throw new \LogicException('Only succeeded withdrawal can be reversed.');
        }
        $this->reversalTransaction = $transaction;
        $this->status = WithdrawalStatus::Reversed;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function wallet(): Wallet
    {
        return $this->wallet;
    }

    public function paymentInstrument(): PaymentInstrument
    {
        return $this->paymentInstrument;
    }

    public function status(): WithdrawalStatus
    {
        return $this->status;
    }

    public function transaction(): ?LedgerTransaction
    {
        return $this->transaction;
    }

    public function reversalTransaction(): ?LedgerTransaction
    {
        return $this->reversalTransaction;
    }

    public function amountMinor(): int
    {
        return $this->amountMinor;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function idempotencyKey(): string
    {
        return $this->idempotencyKey;
    }
}
