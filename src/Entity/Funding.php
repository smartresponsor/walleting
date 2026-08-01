<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\FundingStatus;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'funding')]
#[ORM\UniqueConstraint(name: 'uniq_funding_idempotency_key', columns: ['idempotency_key'])]
class Funding
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

    #[ORM\Column(enumType: FundingStatus::class)]
    private FundingStatus $status;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(Wallet $wallet, PaymentInstrument $paymentInstrument, int $amountMinor, string $currency, string $idempotencyKey, ?Uuid $id = null)
    {
        $currency = strtoupper(trim($currency));
        $idempotencyKey = trim($idempotencyKey);
        if ($amountMinor <= 0 || 1 !== preg_match('/^[A-Z]{3}$/', $currency) || '' === $idempotencyKey) {
            throw new \InvalidArgumentException('Funding amount, currency, and idempotency key are required.');
        }
        if ($paymentInstrument->wallet() !== $wallet) {
            throw new \InvalidArgumentException('Funding instrument must belong to the wallet.');
        }

        $this->id = $id ?? Uuid::v7();
        $this->wallet = $wallet;
        $this->paymentInstrument = $paymentInstrument;
        $this->amountMinor = $amountMinor;
        $this->currency = $currency;
        $this->idempotencyKey = $idempotencyKey;
        $this->status = FundingStatus::Pending;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function succeed(LedgerTransaction $transaction): void
    {
        if (FundingStatus::Pending !== $this->status) { throw new \LogicException('Only pending funding can succeed.'); }
        $this->transaction = $transaction;
        $this->status = FundingStatus::Succeeded;
    }

    public function fail(): void
    {
        if (FundingStatus::Pending !== $this->status) { throw new \LogicException('Only pending funding can fail.'); }
        $this->status = FundingStatus::Failed;
    }

    public function reverse(LedgerTransaction $transaction): void
    {
        if (FundingStatus::Succeeded !== $this->status) { throw new \LogicException('Only succeeded funding can be reversed.'); }
        $this->reversalTransaction = $transaction;
        $this->status = FundingStatus::Reversed;
    }

    public function status(): FundingStatus { return $this->status; }
    public function transaction(): ?LedgerTransaction { return $this->transaction; }
    public function reversalTransaction(): ?LedgerTransaction { return $this->reversalTransaction; }
    public function amountMinor(): int { return $this->amountMinor; }
    public function currency(): string { return $this->currency; }
    public function idempotencyKey(): string { return $this->idempotencyKey; }
}
