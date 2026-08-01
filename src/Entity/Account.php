<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AccountCategory;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'account')]
#[ORM\UniqueConstraint(name: 'uniq_account_wallet_code_currency', columns: ['wallet_id', 'code', 'currency'])]
class Account
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Wallet::class)]
    #[ORM\JoinColumn(name: 'wallet_id', nullable: false, onDelete: 'RESTRICT')]
    private Wallet $wallet;

    #[ORM\Column(length: 64)]
    private string $code;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(enumType: AccountCategory::class)]
    private AccountCategory $category;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(Wallet $wallet, string $code, string $currency, AccountCategory $category, ?Uuid $id = null)
    {
        $code = trim($code);
        $currency = strtoupper(trim($currency));

        if ('' === $code) {
            throw new \InvalidArgumentException('Account code is required.');
        }
        if (1 !== preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new \InvalidArgumentException('Currency must be an ISO 4217 alpha-3 code.');
        }

        $this->id = $id ?? Uuid::v7();
        $this->wallet = $wallet;
        $this->code = $code;
        $this->currency = $currency;
        $this->category = $category;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function id(): Uuid { return $this->id; }
    public function wallet(): Wallet { return $this->wallet; }
    public function code(): string { return $this->code; }
    public function currency(): string { return $this->currency; }
    public function category(): AccountCategory { return $this->category; }
    public function createdAt(): \DateTimeImmutable { return $this->createdAt; }
}
