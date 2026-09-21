<?php

declare(strict_types=1);

namespace App\Walleting\Entity;

use App\Walleting\Enum\WalletReservationStatus;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'reservation')]
#[ORM\UniqueConstraint(name: 'uniq_reservation_idempotency_key', columns: ['idempotency_key'])]
class WalletReservation
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Wallet::class)]
    #[ORM\JoinColumn(name: 'wallet_id', nullable: false, onDelete: 'RESTRICT')]
    private Wallet $wallet;

    #[ORM\ManyToOne(targetEntity: WalletAccount::class)]
    #[ORM\JoinColumn(name: 'account_id', nullable: false, onDelete: 'RESTRICT')]
    private WalletAccount $account;

    #[ORM\ManyToOne(targetEntity: WalletLedgerTransaction::class)]
    #[ORM\JoinColumn(name: 'reserve_transaction_id', nullable: false, onDelete: 'RESTRICT')]
    private WalletLedgerTransaction $reserveTransaction;

    #[ORM\Column(name: 'amount_minor', type: 'bigint')]
    private int $amountMinor;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(name: 'idempotency_key', length: 128, unique: true)]
    private string $idempotencyKey;

    #[ORM\Column(enumType: WalletReservationStatus::class)]
    private WalletReservationStatus $status;

    #[ORM\Column(name: 'expires_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(Wallet $wallet, WalletAccount $account, WalletLedgerTransaction $reserveTransaction, int $amountMinor, string $currency, string $idempotencyKey, ?\DateTimeImmutable $expiresAt = null, ?Uuid $id = null)
    {
        $currency = strtoupper(trim($currency));
        $idempotencyKey = trim($idempotencyKey);
        if ($amountMinor <= 0 || 1 !== preg_match('/^[A-Z]{3}$/', $currency) || '' === $idempotencyKey) {
            throw new \InvalidArgumentException('Reservation amount, currency, and idempotency key are required.');
        }
        if ($account->wallet() !== $wallet) {
            throw new \InvalidArgumentException('Reservation account must belong to the wallet.');
        }
        if ($account->currency() !== $currency) {
            throw new \InvalidArgumentException('Reservation currency must match the account currency.');
        }

        $this->id = $id ?? Uuid::v7();
        $this->wallet = $wallet;
        $this->account = $account;
        $this->reserveTransaction = $reserveTransaction;
        $this->amountMinor = $amountMinor;
        $this->currency = $currency;
        $this->idempotencyKey = $idempotencyKey;
        $this->status = WalletReservationStatus::Active;
        $this->expiresAt = $expiresAt;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function capture(): void
    {
        $this->transition(WalletReservationStatus::Captured);
    }

    public function release(): void
    {
        $this->transition(WalletReservationStatus::Released);
    }

    public function expire(): void
    {
        $this->transition(WalletReservationStatus::Expired);
    }

    public function recordSettlementProgress(int $capturedMinor, int $releasedMinor): void
    {
        if ($capturedMinor < 0 || $releasedMinor < 0 || $capturedMinor + $releasedMinor > $this->amountMinor) {
            throw new \InvalidArgumentException('Reservation settlement totals are invalid.');
        }
        if (in_array($this->status, [WalletReservationStatus::Captured, WalletReservationStatus::Released, WalletReservationStatus::Settled, WalletReservationStatus::Expired], true)) {
            throw new \LogicException('Terminal reservation cannot accept settlement progress.');
        }

        $consumedMinor = $capturedMinor + $releasedMinor;
        $this->status = match (true) {
            $consumedMinor < $this->amountMinor => WalletReservationStatus::PartiallySettled,
            $capturedMinor === $this->amountMinor => WalletReservationStatus::Captured,
            $releasedMinor === $this->amountMinor => WalletReservationStatus::Released,
            default => WalletReservationStatus::Settled,
        };
    }

    private function transition(WalletReservationStatus $status): void
    {
        if (WalletReservationStatus::Active !== $this->status) {
            throw new \LogicException('Only an active reservation can transition.');
        }
        $this->status = $status;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function wallet(): Wallet
    {
        return $this->wallet;
    }

    public function account(): WalletAccount
    {
        return $this->account;
    }

    public function reserveTransaction(): WalletLedgerTransaction
    {
        return $this->reserveTransaction;
    }

    public function status(): WalletReservationStatus
    {
        return $this->status;
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
