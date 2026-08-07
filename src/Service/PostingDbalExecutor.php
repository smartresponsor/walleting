<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\TransactionStatus;
use App\Enum\TransactionType;
use App\Ledger\PostingInstruction;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

final readonly class PostingDbalExecutor
{
    public function __construct(
        private Connection $connection,
        private OutboxService $outboxService,
    ) {
    }

    /** @param non-empty-list<PostingInstruction> $instructions */
    public function execute(
        TransactionType $type,
        string $idempotencyKey,
        string $requestHash,
        array $instructions,
        array $metadata,
    ): string {
        return $this->connection->transactional(function (Connection $connection) use ($type, $idempotencyKey, $requestHash, $instructions, $metadata): string {
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

            return $this->insertPostedTransaction($connection, $type, $idempotencyKey, $requestHash, $instructions, $metadata);
        });
    }

    /** @param non-empty-list<PostingInstruction> $instructions */
    private function insertPostedTransaction(
        Connection $connection,
        TransactionType $type,
        string $idempotencyKey,
        string $requestHash,
        array $instructions,
        array $metadata,
    ): string {
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

        $this->outboxService->enqueueLedgerTransactionPostedDbal(
            $transactionId,
            $type->value,
            $idempotencyKey,
            $requestHash,
            $metadata,
        );

        return $transactionId;
    }
}
