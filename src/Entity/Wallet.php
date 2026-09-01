<?php

declare(strict_types=1);

namespace App\Walleting\Entity;

use App\Objecting\EntityInterface\ObjectEntityInterface;
use App\Objecting\EntityTrait\Embeddable\ObjectAuditEmbeddableTrait;
use App\Objecting\EntityTrait\Embeddable\ObjectIdentityEmbeddableTrait;
use App\Objecting\EntityTrait\Embeddable\ObjectStateEmbeddableTrait;
use App\Objecting\EntityTrait\Embeddable\ObjectTitleEmbeddableTrait;
use App\Walleting\Enum\WalletStatus;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'wallet')]
#[ORM\UniqueConstraint(name: 'uniq_wallet_owner', columns: ['owner_type', 'owner_id'])]
class Wallet implements ObjectEntityInterface
{
    use ObjectIdentityEmbeddableTrait;
    use ObjectTitleEmbeddableTrait;
    use ObjectAuditEmbeddableTrait;
    use ObjectStateEmbeddableTrait;
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(name: 'owner_type', length: 64)]
    private string $ownerType;

    #[ORM\Column(name: 'owner_id', length: 128)]
    private string $ownerId;

    #[ORM\Column(enumType: WalletStatus::class)]
    private WalletStatus $status;

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
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->initializeObjectIdentity($this->id->toRfc4122(), 'wallet:'.$this->id->toRfc4122());
        $this->initializeObjectTitle($ownerType.':'.$ownerId);
        $this->initializeObjectAudit($now);
        $this->initializeObjectState(objectStatus: WalletStatus::Active->value);
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function ownerType(): string
    {
        return $this->ownerType;
    }

    public function ownerId(): string
    {
        return $this->ownerId;
    }

    public function status(): WalletStatus
    {
        return $this->status;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->getObjectCreatedAt();
    }

    public function close(): void
    {
        $this->status = WalletStatus::Closed;
        $this->setObjectStatus(WalletStatus::Closed->value);
        $this->setObjectActive(false);
        $this->touchModified();
    }
}
