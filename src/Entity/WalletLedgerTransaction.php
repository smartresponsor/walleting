<?php

declare(strict_types=1);

namespace App\Walleting\Entity;

use App\Walleting\Enum\WalletTransactionStatus;
use App\Walleting\Enum\WalletTransactionType;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'ledger_transaction')]
#[ORM\Index(name: 'idx_ledger_transaction_posted_chronology', columns: ['posted_at', 'id'], options: ['where' => "((status)::text = 'posted'::text)"])]
#[ORM\UniqueConstraint(name: 'uniq_ledger_transaction_idempotency_key', columns: ['idempotency_key'])]
class WalletLedgerTransaction
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(enumType: WalletTransactionType::class)]
    private WalletTransactionType $type;

    #[ORM\Column(enumType: WalletTransactionStatus::class)]
    private WalletTransactionStatus $status;

    #[ORM\Column(name: 'idempotency_key', length: 128, unique: true)]
    private string $idempotencyKey;

    #[ORM\Column(name: 'request_hash', length: 64)]
    private string $requestHash;

    #[ORM\Column(type: 'json')]
    private array $metadata;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'posted_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $postedAt = null;

    /** @var Collection<int, WalletPosting> */
    #[ORM\OneToMany(mappedBy: 'transaction', targetEntity: WalletPosting::class, cascade: ['persist'])]
    private Collection $postings;

    public function __construct(WalletTransactionType $type, string $idempotencyKey, array $metadata = [], ?Uuid $id = null, ?string $requestHash = null)
    {
        $idempotencyKey = trim($idempotencyKey);
        $requestHash ??= hash('sha256', $type->value.'|'.$idempotencyKey);
        if ('' === $idempotencyKey || 1 !== preg_match('/^[a-f0-9]{64}$/', $requestHash)) {
            throw new \InvalidArgumentException('Idempotency key and SHA-256 request hash are required.');
        }

        $this->id = $id ?? Uuid::v7();
        $this->type = $type;
        $this->status = WalletTransactionStatus::Pending;
        $this->idempotencyKey = $idempotencyKey;
        $this->requestHash = $requestHash;
        $this->metadata = $metadata;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->postings = new ArrayCollection();
    }

    public function addPosting(WalletAccount $account, int $amountMinor, ?Uuid $id = null): WalletPosting
    {
        if (WalletTransactionStatus::Pending !== $this->status) {
            throw new \LogicException('Postings can only be added to a pending transaction.');
        }

        $firstPosting = $this->postings->first();
        if ($firstPosting instanceof WalletPosting && $firstPosting->currency() !== $account->currency()) {
            throw new \InvalidArgumentException('A ledger transaction cannot contain multiple currencies.');
        }

        $posting = new WalletPosting($this, $account, $amountMinor, $this->postings->count() + 1, $id);
        $this->postings->add($posting);

        return $posting;
    }

    public function post(): void
    {
        if ($this->postings->count() < 2) {
            throw new \LogicException('A ledger transaction requires at least two postings.');
        }

        $sum = 0;
        foreach ($this->postings as $posting) {
            $sum += $posting->amountMinor();
        }
        if (0 !== $sum) {
            throw new \LogicException('Ledger transaction postings must balance to zero.');
        }

        $this->status = WalletTransactionStatus::Posted;
        $this->postedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function type(): WalletTransactionType
    {
        return $this->type;
    }

    public function status(): WalletTransactionStatus
    {
        return $this->status;
    }

    public function idempotencyKey(): string
    {
        return $this->idempotencyKey;
    }

    public function requestHash(): string
    {
        return $this->requestHash;
    }

    public function metadata(): array
    {
        return $this->metadata;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function postedAt(): ?\DateTimeImmutable
    {
        return $this->postedAt;
    }

    /** @return Collection<int, WalletPosting> */
    public function postings(): Collection
    {
        return $this->postings;
    }
}
