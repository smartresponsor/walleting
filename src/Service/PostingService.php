<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\LedgerTransaction;
use App\Enum\TransactionStatus;
use App\Enum\TransactionType;
use App\Ledger\PostingInstruction;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class PostingService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ?OutboxService $outboxService = null,
        private ?Connection $connection = null,
    ) {
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
            $connection = $this->connection ?? throw new \LogicException('PostingService DBAL connection is required for standalone posting.');
            $transactionId = $connection->transactional(function (Connection $connection) use ($type, $idempotencyKey, $instructions, $metadata, $requestHash): string {
                $accountIds = array_values(array_unique(array_map(
                    static fn (PostingInstruction $instruction): string => $instruction->account->id()->toRfc4122(),
                    $instructions,
                )));
                sort($accountIds, SORT_STRING);

                $lockedAccountIds = array_map('strval', $connection->fetchFirstColumn(
                    'SELECT id FROM account WHERE id IN (?) ORDER BY id FOR UPDATE',
                    [$accountIds],
                    [ArrayParameterType::STRING],
                ));
                if ($lockedAccountIds !== $accountIds) {
                    throw new \RuntimeException('Every posting account must already exist before standalone posting.');
                }

                $transactionId = Uuid::v7()->toRfc4122();
                $timestamp = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
                $connection->insert('ledger_transaction', [
                    'id' => $transactionId,
                    'type' => $type->value,
                    'status' => TransactionStatus::Posted->value,
                    'idempotency_key' => $idempotencyKey,
                    'request_hash' => $requestHash,
                    'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
                    'created_at' => $timestamp,
                    'posted_at' => $timestamp,
                ]);

                foreach ($instructions as $index => $instruction) {
                    $connection->insert('posting', [
                        'id' => Uuid::v7()->toRfc4122(),
                        'transaction_id' => $transactionId,
                        'account_id' => $instruction->account->id()->toRfc4122(),
                        'amount_minor' => $instruction->amountMinor,
                        'currency' => $instruction->account->currency(),
                        'sequence' => $index + 1,
                        'created_at' => $timestamp,
                    ], ['amount_minor' => ParameterType::INTEGER, 'sequence' => ParameterType::INTEGER]);
                }

                $this->outboxService?->enqueueLedgerTransactionPostedDbal(
                    $transactionId,
                    $type->value,
                    $idempotencyKey,
                    $requestHash,
                    $metadata,
                );

                return $transactionId;
            });

            $transaction = $this->entityManager->find(LedgerTransaction::class, Uuid::fromString($transactionId));
            if (!$transaction instanceof LedgerTransaction) {
                throw new \RuntimeException('Posted ledger transaction could not be hydrated after commit.');
            }

            return $transaction;
        } catch (UniqueConstraintViolationException $exception) {
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
        $this->emitPosted($transaction);

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

    private function emitPosted(LedgerTransaction $transaction): void
    {
        $this->outboxService?->enqueueManaged(
            'ledger.transaction.posted',
            'ledger.transaction.posted:'.$transaction->id()->toRfc4122(),
            [
                'transaction_id' => $transaction->id()->toRfc4122(),
                'transaction_type' => $transaction->type()->value,
                'idempotency_key' => $transaction->idempotencyKey(),
                'request_hash' => $transaction->requestHash(),
                'metadata' => $transaction->metadata(),
            ],
            ledgerTransaction: $transaction,
        );
    }
}
