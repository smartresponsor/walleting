<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AccountType;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'ledger_account')]
#[ORM\UniqueConstraint(name: 'uniq_account_wallet_code', columns: ['wallet_id', 'code'])]
class Account
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;
    #[ORM\ManyToOne(targetEntity: Wallet::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Wallet $wallet;
    #[ORM\Column(length: 64)]
    private string $code;
    #[ORM\Column(enumType: AccountType::class)]
    private AccountType $type;
    #[ORM\Column(length: 3)]
    private string $currency;
    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(Wallet $wallet, string $code, AccountType $type)
    {
        if ('' === trim($code)) {
            throw new \InvalidArgumentException('Account code is required.');
        }
        $this->id = Uuid::v7();
        $this->wallet = $wallet;
        $this->code = $code;
        $this->type = $type;
        $this->currency = $wallet->currency();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function id(): Uuid { return $this->id; }
    public function currency(): string { return $this->currency; }
    public function isActive(): bool { return $this->active; }
}
