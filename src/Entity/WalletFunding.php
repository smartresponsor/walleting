<?php

declare(strict_types=1);

namespace App\Walleting\Entity;

use App\Walleting\Enum\WalletFundingStatus;
use App\Walleting\Enum\WalletPaymentInstrumentStatus;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'funding')]
#[ORM\UniqueConstraint(name: 'uniq_funding_idempotency_key', columns: ['idempotency_key'])]
#[ORM\UniqueConstraint(name: 'uniq_funding_provider_operation_reference', columns: ['provider_operation_reference'])]
class WalletFunding
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Wallet::class)]
    #[ORM\JoinColumn(name: 'wallet_id', nullable: false, onDelete: 'RESTRICT')]
    private Wallet $wallet;

    #[ORM\ManyToOne(targetEntity: WalletPaymentInstrument::class)]
    #[ORM\JoinColumn(name: 'payment_instrument_id', nullable: false, onDelete: 'RESTRICT')]
    private WalletPaymentInstrument $paymentInstrument;

    #[ORM\ManyToOne(targetEntity: WalletLedgerTransaction::class)]
    #[ORM\JoinColumn(name: 'transaction_id', nullable: true, onDelete: 'RESTRICT')]
    private ?WalletLedgerTransaction $transaction = null;

    #[ORM\OneToOne(targetEntity: WalletLedgerTransaction::class)]
    #[ORM\JoinColumn(name: 'reversal_transaction_id', nullable: true, unique: true, onDelete: 'RESTRICT')]
    private ?WalletLedgerTransaction $reversalTransaction = null;

    #[ORM\Column(name: 'amount_minor', type: 'bigint')]
    private int $amountMinor;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(name: 'idempotency_key', length: 128, unique: true)]
    private string $idempotencyKey;

    #[ORM\Column(enumType: WalletFundingStatus::class)]
    private WalletFundingStatus $status;

    #[ORM\Column(name: 'provider_operation_reference', length: 191, nullable: true)]
    private ?string $providerOperationReference = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(Wallet $wallet, WalletPaymentInstrument $paymentInstrument, int $amountMinor, string $currency, string $idempotencyKey, ?Uuid $id = null)
    {
        $currency = strtoupper(trim($currency));
        $idempotencyKey = trim($idempotencyKey);
        if ($amountMinor <= 0 || 1 !== preg_match('/^[A-Z]{3}$/', $currency) || '' === $idempotencyKey) {
            throw new \InvalidArgumentException('Funding amount, currency, and idempotency key are required.');
        }
        if ($paymentInstrument->wallet() !== $wallet) {
            throw new \InvalidArgumentException('Funding instrument must belong to the wallet.');
        }
        if (WalletPaymentInstrumentStatus::Active !== $paymentInstrument->status()) {
            throw new \InvalidArgumentException('Funding instrument must be active.');
        }

        $this->id = $id ?? Uuid::v7();
        $this->wallet = $wallet;
        $this->paymentInstrument = $paymentInstrument;
        $this->amountMinor = $amountMinor;
        $this->currency = $currency;
        $this->idempotencyKey = $idempotencyKey;
        $this->status = WalletFundingStatus::Pending;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function start(): void
    {
        if (WalletFundingStatus::Pending !== $this->status) {
            throw new \LogicException('Only pending funding can start.');
        }
        $this->status = WalletFundingStatus::Processing;
    }

    public function bindProviderOperationReference(string $reference): void
    {
        $reference = trim($reference);
        if ('' === $reference) {
            throw new \InvalidArgumentException('Funding provider operation reference is required.');
        }
        if (null !== $this->providerOperationReference) {
            if ($this->providerOperationReference !== $reference) {
                throw new \DomainException('Funding is already bound to a different provider operation reference.');
            }

            return;
        }
        if (WalletFundingStatus::Processing !== $this->status) {
            throw new \LogicException('Funding provider operation reference can only be bound while processing.');
        }

        $this->providerOperationReference = $reference;
    }

    public function succeed(WalletLedgerTransaction $transaction): void
    {
        if (WalletFundingStatus::Processing !== $this->status) {
            throw new \LogicException('Only processing funding can succeed.');
        }
        $this->transaction = $transaction;
        $this->status = WalletFundingStatus::Succeeded;
    }

    public function fail(): void
    {
        if (!in_array($this->status, [WalletFundingStatus::Pending, WalletFundingStatus::Processing], true)) {
            throw new \LogicException('Funding cannot fail from its current status.');
        }
        $this->status = WalletFundingStatus::Failed;
    }

    public function reverse(WalletLedgerTransaction $transaction): void
    {
        if (WalletFundingStatus::Succeeded !== $this->status) {
            throw new \LogicException('Only succeeded funding can be reversed.');
        }
        $this->reversalTransaction = $transaction;
        $this->status = WalletFundingStatus::Reversed;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function wallet(): Wallet
    {
        return $this->wallet;
    }

    public function paymentInstrument(): WalletPaymentInstrument
    {
        return $this->paymentInstrument;
    }

    public function status(): WalletFundingStatus
    {
        return $this->status;
    }

    public function transaction(): ?WalletLedgerTransaction
    {
        return $this->transaction;
    }

    public function reversalTransaction(): ?WalletLedgerTransaction
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

    public function providerOperationReference(): ?string
    {
        return $this->providerOperationReference;
    }
}
