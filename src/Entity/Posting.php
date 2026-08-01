<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'posting')]
#[ORM\Index(name: 'idx_posting_transaction', columns: ['transaction_id'])]
#[ORM\Index(name: 'idx_posting_account', columns: ['account_id'])]
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

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(LedgerTransaction $transaction, Account $account, int $amountMinor, ?Uuid $id = null)
    {
        if (0 === $amountMinor) {
            throw new \InvalidArgumentException('Posting amount cannot be zero.');
        }

        $this->id = $id ?? Uuid::v7();
        $this->transaction = $transaction;
        $this->account = $account;
        $this->amountMinor = $amountMinor;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function id(): Uuid { return $this->id; }
    public function transaction(): LedgerTransaction { return $this->transaction; }
    public function account(): Account { return $this->account; }
    public function amountMinor(): int { return $this->amountMinor; }
    public function createdAt(): \DateTimeImmutable { return $this->createdAt; }
}
