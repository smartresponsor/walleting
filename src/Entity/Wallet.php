<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'wallet')]
#[ORM\UniqueConstraint(name: 'uniq_wallet_owner_currency', columns: ['owner_id', 'currency'])]
class Wallet
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;
    #[ORM\Column(length: 128)]
    private string $ownerId;
    #[ORM\Column(length: 3)]
    private string $currency;
    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $ownerId, string $currency)
    {
        $currency = strtoupper($currency);
        if ('' === trim($ownerId) || 1 !== preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new \InvalidArgumentException('Wallet owner and ISO 4217 currency are required.');
        }
        $this->id = Uuid::v7();
        $this->ownerId = $ownerId;
        $this->currency = $currency;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function id(): Uuid { return $this->id; }
    public function currency(): string { return $this->currency; }
    public function isActive(): bool { return $this->active; }
    public function deactivate(): void { $this->active = false; }
}
