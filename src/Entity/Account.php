<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AccountCategory;
use App\Objecting\EntityInterface\ObjectEntityInterface;
use App\Objecting\EntityTrait\Embeddable\ObjectAuditEmbeddableTrait;
use App\Objecting\EntityTrait\Embeddable\ObjectIdentityEmbeddableTrait;
use App\Objecting\EntityTrait\Embeddable\ObjectStateEmbeddableTrait;
use App\Objecting\EntityTrait\Embeddable\ObjectTitleEmbeddableTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'account')]
#[ORM\UniqueConstraint(name: 'uniq_account_wallet_code_currency', columns: ['wallet_id', 'code', 'currency'])]
class Account implements ObjectEntityInterface
{
    use ObjectIdentityEmbeddableTrait;
    use ObjectTitleEmbeddableTrait;
    use ObjectAuditEmbeddableTrait;
    use ObjectStateEmbeddableTrait;
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

    #[ORM\Column(name: 'allow_negative')]
    private bool $allowNegative;

    public function __construct(Wallet $wallet, string $code, string $currency, AccountCategory $category, ?Uuid $id = null, ?bool $allowNegative = null)
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
        $this->allowNegative = $allowNegative ?? !in_array($category, [AccountCategory::Asset, AccountCategory::Reserve], true);
        $now = new \DateTimeImmutable();
        $this->initializeObjectIdentity($this->id->toRfc4122(), 'account:'.$this->id->toRfc4122());
        $this->initializeObjectTitle($code);
        $this->initializeObjectAudit($now);
        $this->initializeObjectState(objectStatus: 'active');
    }

    public function id(): Uuid { return $this->id; }
    public function wallet(): Wallet { return $this->wallet; }
    public function code(): string { return $this->code; }
    public function currency(): string { return $this->currency; }
    public function category(): AccountCategory { return $this->category; }
    public function allowsNegativeBalance(): bool { return $this->allowNegative; }
    public function createdAt(): \DateTimeImmutable { return $this->getCreatedAt(); }
}
