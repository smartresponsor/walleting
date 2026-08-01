<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\LedgerTransaction;
use App\Enum\TransactionStatus;
use App\Enum\TransactionType;
use App\Ledger\PostingInstruction;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

final readonly class PostingService
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /** @param non-empty-list<PostingInstruction> $instructions */
    public function post(
        TransactionType $type,
        string $idempotencyKey,
        array $instructions,
        array $metadata = [],
    ): LedgerTransaction {
        $idempotencyKey = trim($idempotencyKey);
        $this->validate($idempotencyKey, $instructions);
        $requestHash = $this->requestHash($type, $instructions, $metadata);

        $existing = $this->findExisting($idempotencyKey);
        if ($existing instanceof LedgerTransaction) {
            $this->assertSameRequest($existing, $requestHash);

            return $existing;
        }

        try {
            return $this->entityManager->wrapInTransaction(function () use ($type, $idempotencyKey, $instructions, $metadata): LedgerTransaction {
                $transaction = $this->postManaged($type, $idempotencyKey, $instructions, $metadata);
                $this->entityManager->flush();

                return $transaction;
            });
        } catch (UniqueConstraintViolationException $exception) {
            $this->entityManager->clear();
            $existing = $this->findExisting($idempotencyKey);
            if ($existing instanceof LedgerTransaction && TransactionStatus::Posted === $existing->status()) {
                $this->assertSameRequest($existing, $requestHash);

                return $existing;
            }

            throw $exception;
        }
    }

    /** @param non-empty-list<PostingInstruction> $instructions */
    public function postManaged(TransactionType $type, string $idempotencyKey, array $instructions, array $metadata = []): LedgerTransaction
    {
        $idempotencyKey = trim($idempotencyKey);
        $this->validate($idempotencyKey, $instructions);
        $requestHash = $this->requestHash($type, $instructions, $metadata);

        $existing = $this->findExisting($idempotencyKey);
        if ($existing instanceof LedgerTransaction) {
            $this->assertSameRequest($existing, $requestHash);

            return $existing;
        }

        $transaction = new LedgerTransaction($type, $idempotencyKey, $metadata, requestHash: $requestHash);
        foreach ($instructions as $instruction) {
            $transaction->addPosting($instruction->account, $instruction->amountMinor);
        }
        $transaction->post();
        $this->entityManager->persist($transaction);

        return $transaction;
    }

    /** @param non-empty-list<PostingInstruction> $instructions */
    public function debit(string $idempotencyKey, array $instructions, array $metadata = []): LedgerTransaction
    {
        return $this->post(TransactionType::Debit, $idempotencyKey, $instructions, $metadata);
    }

    /** @param non-empty-list<PostingInstruction> $instructions */
    public function credit(string $idempotencyKey, array $instructions, array $metadata = []): LedgerTransaction
    {
        return $this->post(TransactionType::Credit, $idempotencyKey, $instructions, $metadata);
    }

    /** @param non-empty-list<PostingInstruction> $instructions */
    public function transfer(string $idempotencyKey, array $instructions, array $metadata = []): LedgerTransaction
    {
        return $this->post(TransactionType::Transfer, $idempotencyKey, $instructions, $metadata);
    }

    /** @param list<PostingInstruction> $instructions */
    private function validate(string $idempotencyKey, array $instructions): void
    {
        if ('' === $idempotencyKey || count($instructions) < 2) {
            throw new \InvalidArgumentException('Idempotency key and at least two posting instructions are required.');
        }

        $currency = null;
        $balance = 0;
        foreach ($instructions as $instruction) {
            if (!$instruction instanceof PostingInstruction) {
                throw new \InvalidArgumentException('Every instruction must be a PostingInstruction.');
            }
            $currency ??= $instruction->account->currency();
            if ($currency !== $instruction->account->currency()) {
                throw new \InvalidArgumentException('One ledger transaction cannot contain multiple currencies.');
            }
            $balance += $instruction->amountMinor;
        }

        if (0 !== $balance) {
            throw new \InvalidArgumentException('Posting instructions must balance to zero.');
        }
    }

    private function findExisting(string $idempotencyKey): ?LedgerTransaction
    {
        return $this->entityManager->getRepository(LedgerTransaction::class)->findOneBy(['idempotencyKey' => $idempotencyKey]);
    }

    /** @param non-empty-list<PostingInstruction> $instructions */
    private function requestHash(TransactionType $type, array $instructions, array $metadata): string
    {
        $payload = [
            'type' => $type->value,
            'postings' => array_map(static fn (PostingInstruction $instruction): array => [
                'account_id' => $instruction->account->id()->toRfc4122(),
                'amount_minor' => $instruction->amountMinor,
                'currency' => $instruction->account->currency(),
            ], $instructions),
            'metadata' => $this->normalize($metadata),
        ];

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function assertSameRequest(LedgerTransaction $existing, string $requestHash): void
    {
        if (!hash_equals($existing->requestHash(), $requestHash)) {
            throw new \DomainException('Idempotency key is already bound to a different financial request.');
        }
    }

    private function normalize(array $value): array
    {
        ksort($value);
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = $this->normalize($item);
            }
        }
        unset($item);

        return $value;
    }
}
