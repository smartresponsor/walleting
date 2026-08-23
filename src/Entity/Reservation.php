<?php

declare(strict_types=1);

namespace App\Walleting\Entity;

use App\Walleting\Enum\ReservationStatus;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'reservation')]
#[ORM\UniqueConstraint(name: 'uniq_reservation_idempotency_key', columns: ['idempotency_key'])]
class Reservation
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Wallet::class)]
    #[ORM\JoinColumn(name: 'wallet_id', nullable: false, onDelete: 'RESTRICT')]
    private Wallet $wallet;

    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(name: 'account_id', nullable: false, onDelete: 'RESTRICT')]
    private Account $account;

    #[ORM\ManyToOne(targetEntity: LedgerTransaction::class)]
    #[ORM\JoinColumn(name: 'reserve_transaction_id', nullable: false, onDelete: 'RESTRICT')]
    private LedgerTransaction $reserveTransaction;

    #[ORM\Column(name: 'amount_minor', type: 'bigint')]
    private int $amountMinor;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(name: 'idempotency_key', length: 128, unique: true)]
    private string $idempotencyKey;

    #[ORM\Column(enumType: ReservationStatus::class)]
    private ReservationStatus $status;

    #[ORM\Column(name: 'expires_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(Wallet $wallet, Account $account, LedgerTransaction $reserveTransaction, int $amountMinor, string $currency, string $idempotencyKey, ?\DateTimeImmutable $expiresAt = null, ?Uuid $id = null)
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
        $this->status = ReservationStatus::Active;
        $this->expiresAt = $expiresAt;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function capture(): void
    {
        $this->transition(ReservationStatus::Captured);
    }

    public function release(): void
    {
        $this->transition(ReservationStatus::Released);
    }

    public function expire(): void
    {
        $this->transition(ReservationStatus::Expired);
    }

    public function recordSettlementProgress(int $capturedMinor, int $releasedMinor): void
    {
        if ($capturedMinor < 0 || $releasedMinor < 0 || $capturedMinor + $releasedMinor > $this->amountMinor) {
            throw new \InvalidArgumentException('Reservation settlement totals are invalid.');
        }
        if (in_array($this->status, [ReservationStatus::Captured, ReservationStatus::Released, ReservationStatus::Settled, ReservationStatus::Expired], true)) {
            throw new \LogicException('Terminal reservation cannot accept settlement progress.');
        }

        $consumedMinor = $capturedMinor + $releasedMinor;
        $this->status = match (true) {
            $consumedMinor < $this->amountMinor => ReservationStatus::PartiallySettled,
            $capturedMinor === $this->amountMinor => ReservationStatus::Captured,
            $releasedMinor === $this->amountMinor => ReservationStatus::Released,
            default => ReservationStatus::Settled,
        };
    }

    private function transition(ReservationStatus $status): void
    {
        if (ReservationStatus::Active !== $this->status) {
            throw new \LogicException('Only an active reservation can transition.');
        }
        $this->status = $status;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function account(): Account
    {
        return $this->account;
    }

    public function reserveTransaction(): LedgerTransaction
    {
        return $this->reserveTransaction;
    }

    public function status(): ReservationStatus
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
