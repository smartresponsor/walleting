<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\TransactionStatus;
use App\Ledger\FinancialPostingRequest;
use App\Ledger\PostingInstruction;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

final readonly class PostingDbalExecutor implements PostingExecutorInterface
{
    public function __construct(
        private Connection $connection,
        private OutboxService $outboxService,
        private PostingRetryPolicy $retryPolicy,
        private int $lockTimeoutMilliseconds = 1000,
    ) {
        if ($lockTimeoutMilliseconds < 1 || $lockTimeoutMilliseconds > 60000) {
            throw new \InvalidArgumentException('Posting lock timeout must be between 1 and 60000 milliseconds.');
        }
    }

    public function execute(string $idempotencyKey, FinancialPostingRequest $request): string
    {
        $attempt = 0;
        while (true) {
            ++$attempt;
            try {
                return $this->executeOnce($idempotencyKey, $request);
            } catch (\Throwable $exception) {
                if (!$this->retryPolicy->shouldRetry($exception, $attempt)) {
                    throw $exception;
                }

                usleep($this->retryPolicy->delayMicroseconds($attempt));
            }
        }
    }

    private function executeOnce(string $idempotencyKey, FinancialPostingRequest $request): string
    {
        return $this->connection->transactional(function (Connection $connection) use ($idempotencyKey, $request): string {
            $connection->executeStatement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $connection->executeStatement(sprintf("SET LOCAL lock_timeout = '%dms'", $this->lockTimeoutMilliseconds));
            $accountIds = array_values(array_unique(array_map(
                static fn (PostingInstruction $instruction): string => $instruction->account->id()->toRfc4122(),
                $request->instructions,
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

            return $this->insertPostedTransaction($connection, $idempotencyKey, $request);
        });
    }

    private function insertPostedTransaction(
        Connection $connection,
        string $idempotencyKey,
        FinancialPostingRequest $request,
    ): string {
        $transactionId = Uuid::v7()->toRfc4122();
        $timestamp = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $connection->insert('ledger_transaction', [
            'id' => $transactionId,
            'type' => $request->type->value,
            'status' => TransactionStatus::Posted->value,
            'idempotency_key' => $idempotencyKey,
            'request_hash' => $request->hash(),
            'metadata' => json_encode($request->metadata, JSON_THROW_ON_ERROR),
            'created_at' => $timestamp,
            'posted_at' => $timestamp,
        ]);

        foreach ($request->instructions as $index => $instruction) {
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
            $request->type->value,
            $idempotencyKey,
            $request->hash(),
            $request->metadata,
        );

        return $transactionId;
    }
}
