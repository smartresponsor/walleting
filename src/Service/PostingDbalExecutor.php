<?php

declare(strict_types=1);

namespace App\Walleting\Service;

use App\Walleting\Enum\TransactionStatus;
use App\Walleting\Ledger\FinancialPostingRequest;
use App\Walleting\Ledger\PostingInstruction;
use App\Walleting\Posting\PostingExecutionMetric;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

final readonly class PostingDbalExecutor implements PostingExecutorInterface
{
    public function __construct(
        private Connection $connection,
        private OutboxService $outboxService,
        private PostingRetryPolicy $retryPolicy,
        private PostingTelemetryInterface $telemetry,
        private int $lockTimeoutMilliseconds = 1000,
    ) {
        if ($lockTimeoutMilliseconds < 1 || $lockTimeoutMilliseconds > 60000) {
            throw new \InvalidArgumentException('Posting lock timeout must be between 1 and 60000 milliseconds.');
        }
    }

    public function execute(string $idempotencyKey, FinancialPostingRequest $request): string
    {
        $startedAt = hrtime(true);
        $attempt = 0;
        while (true) {
            ++$attempt;
            $attemptStartedAt = hrtime(true);
            try {
                $transactionId = $this->executeOnce($idempotencyKey, $request);
                $attemptDuration = $this->elapsedMilliseconds($attemptStartedAt);
                $this->recordMetric(new PostingExecutionMetric(
                    'completed',
                    $request->type->value,
                    $attempt,
                    $attempt - 1,
                    null,
                    $attemptDuration,
                    $this->elapsedMilliseconds($startedAt),
                    null,
                ));

                return $transactionId;
            } catch (\Throwable $exception) {
                $attemptDuration = $this->elapsedMilliseconds($attemptStartedAt);
                $reason = $this->retryPolicy->retryReason($exception);
                if (!$this->retryPolicy->shouldRetry($exception, $attempt)) {
                    if ($exception instanceof UniqueConstraintViolationException) {
                        throw $exception;
                    }

                    $this->recordMetric(new PostingExecutionMetric(
                        'failed',
                        $request->type->value,
                        $attempt,
                        $attempt - 1,
                        $reason,
                        $attemptDuration,
                        $this->elapsedMilliseconds($startedAt),
                        'lock_timeout' === $reason ? $attemptDuration : null,
                    ));
                    throw $exception;
                }

                $this->recordMetric(new PostingExecutionMetric(
                    'retry',
                    $request->type->value,
                    $attempt,
                    $attempt,
                    $reason,
                    $attemptDuration,
                    $this->elapsedMilliseconds($startedAt),
                    'lock_timeout' === $reason ? $attemptDuration : null,
                ));
                usleep($this->retryPolicy->delayMicroseconds($attempt));
            }
        }
    }

    private function elapsedMilliseconds(int $startedAt): int
    {
        return max(0, (int) floor((hrtime(true) - $startedAt) / 1_000_000));
    }

    private function recordMetric(PostingExecutionMetric $metric): void
    {
        try {
            $this->telemetry->record($metric);
        } catch (\Throwable) {
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
        $timestamp = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
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
