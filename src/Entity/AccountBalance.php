<?php

declare(strict_types=1);

namespace App\Walleting\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'account_balance')]
class AccountBalance
{
    #[ORM\Id]
    #[ORM\OneToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(name: 'account_id', onDelete: 'RESTRICT')]
    private Account $account;

    #[ORM\Column(name: 'balance_minor', type: 'bigint')]
    private int $balanceMinor;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(name: 'posting_count', type: 'bigint')]
    private int $postingCount;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Account $account)
    {
        $this->account = $account;
        $this->balanceMinor = 0;
        $this->currency = $account->currency();
        $this->postingCount = 0;
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function account(): Account
    {
        return $this->account;
    }

    public function balanceMinor(): int
    {
        return $this->balanceMinor;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function postingCount(): int
    {
        return $this->postingCount;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
