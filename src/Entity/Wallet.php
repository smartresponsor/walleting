<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\WalletStatus;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'wallet')]
#[ORM\UniqueConstraint(name: 'uniq_wallet_owner', columns: ['owner_type', 'owner_id'])]
class Wallet
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(name: 'owner_type', length: 64)]
    private string $ownerType;

    #[ORM\Column(name: 'owner_id', length: 128)]
    private string $ownerId;

    #[ORM\Column(enumType: WalletStatus::class)]
    private WalletStatus $status;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $ownerType, string $ownerId, ?Uuid $id = null)
    {
        $ownerType = trim($ownerType);
        $ownerId = trim($ownerId);

        if ('' === $ownerType || '' === $ownerId) {
            throw new \InvalidArgumentException('Wallet owner type and owner id are required.');
        }

        $this->id = $id ?? Uuid::v7();
        $this->ownerType = $ownerType;
        $this->ownerId = $ownerId;
        $this->status = WalletStatus::Active;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function id(): Uuid { return $this->id; }
    public function ownerType(): string { return $this->ownerType; }
    public function ownerId(): string { return $this->ownerId; }
    public function status(): WalletStatus { return $this->status; }
    public function createdAt(): \DateTimeImmutable { return $this->createdAt; }

    public function close(): void
    {
        $this->status = WalletStatus::Closed;
    }
}
