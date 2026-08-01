<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\TransactionStatus;
use App\Enum\TransactionType;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'ledger_transaction')]
#[ORM\UniqueConstraint(name: 'uniq_ledger_transaction_idempotency_key', columns: ['idempotency_key'])]
class LedgerTransaction
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(enumType: TransactionType::class)]
    private TransactionType $type;

    #[ORM\Column(enumType: TransactionStatus::class)]
    private TransactionStatus $status;

    #[ORM\Column(name: 'idempotency_key', length: 128, unique: true)]
    private string $idempotencyKey;

    #[ORM\Column(type: 'json')]
    private array $metadata;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'posted_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $postedAt = null;

    /** @var Collection<int, Posting> */
    #[ORM\OneToMany(mappedBy: 'transaction', targetEntity: Posting::class, cascade: ['persist'])]
    private Collection $postings;

    public function __construct(TransactionType $type, string $idempotencyKey, array $metadata = [], ?Uuid $id = null)
    {
        $idempotencyKey = trim($idempotencyKey);
        if ('' === $idempotencyKey) {
            throw new \InvalidArgumentException('Idempotency key is required.');
        }

        $this->id = $id ?? Uuid::v7();
        $this->type = $type;
        $this->status = TransactionStatus::Pending;
        $this->idempotencyKey = $idempotencyKey;
        $this->metadata = $metadata;
        $this->createdAt = new \DateTimeImmutable();
        $this->postings = new ArrayCollection();
    }

    public function addPosting(Account $account, int $amountMinor, ?Uuid $id = null): Posting
    {
        if (TransactionStatus::Pending !== $this->status) {
            throw new \LogicException('Postings can only be added to a pending transaction.');
        }

        $firstPosting = $this->postings->first();
        if ($firstPosting instanceof Posting && $firstPosting->currency() !== $account->currency()) {
            throw new \InvalidArgumentException('A ledger transaction cannot contain multiple currencies.');
        }

        $posting = new Posting($this, $account, $amountMinor, $this->postings->count() + 1, $id);
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

        $this->status = TransactionStatus::Posted;
        $this->postedAt = new \DateTimeImmutable();
    }

    public function id(): Uuid { return $this->id; }
    public function type(): TransactionType { return $this->type; }
    public function status(): TransactionStatus { return $this->status; }
    public function idempotencyKey(): string { return $this->idempotencyKey; }
    public function metadata(): array { return $this->metadata; }
    public function createdAt(): \DateTimeImmutable { return $this->createdAt; }
    public function postedAt(): ?\DateTimeImmutable { return $this->postedAt; }
    /** @return Collection<int, Posting> */
    public function postings(): Collection { return $this->postings; }
}
