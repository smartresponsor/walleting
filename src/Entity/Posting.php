<?php

declare(strict_types=1);

namespace App\Walleting\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'posting')]
#[ORM\Index(name: 'idx_posting_transaction', columns: ['transaction_id'])]
#[ORM\Index(name: 'idx_posting_account', columns: ['account_id'])]
#[ORM\UniqueConstraint(name: 'uniq_posting_transaction_sequence', columns: ['transaction_id', 'sequence'])]
class Posting
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: LedgerTransaction::class, inversedBy: 'postings')]
    #[ORM\JoinColumn(name: 'transaction_id', nullable: false, onDelete: 'RESTRICT')]
    private LedgerTransaction $transaction;

    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(name: 'account_id', nullable: false, onDelete: 'RESTRICT')]
    private Account $account;

    #[ORM\Column(name: 'amount_minor', type: 'bigint')]
    private int $amountMinor;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(type: 'integer')]
    private int $sequence;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(LedgerTransaction $transaction, Account $account, int $amountMinor, int $sequence, ?Uuid $id = null)
    {
        if (0 === $amountMinor || $sequence < 1) {
            throw new \InvalidArgumentException('Posting amount must be non-zero and sequence must be positive.');
        }

        $this->id = $id ?? Uuid::v7();
        $this->transaction = $transaction;
        $this->account = $account;
        $this->amountMinor = $amountMinor;
        $this->currency = $account->currency();
        $this->sequence = $sequence;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function transaction(): LedgerTransaction
    {
        return $this->transaction;
    }

    public function account(): Account
    {
        return $this->account;
    }

    public function amountMinor(): int
    {
        return $this->amountMinor;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function sequence(): int
    {
        return $this->sequence;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
