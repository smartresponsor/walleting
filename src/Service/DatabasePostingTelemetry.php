<?php

declare(strict_types=1);

namespace App\Walleting\Service;

use App\Walleting\Posting\PostingExecutionMetric;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DatabasePostingTelemetry implements PostingTelemetryInterface
{
    public function __construct(
        private Connection $connection,
        private LoggerInterface $logger,
    ) {
    }

    public function record(PostingExecutionMetric $metric): void
    {
        $this->connection->insert('posting_metric_sample', [
            'id' => Uuid::v7()->toRfc4122(),
            'event' => $metric->event,
            'transaction_type' => $metric->transactionType,
            'attempt' => $metric->attempt,
            'retry_count' => $metric->retryCount,
            'retry_reason' => $metric->retryReason,
            'attempt_duration_ms' => $metric->attemptDurationMilliseconds,
            'total_duration_ms' => $metric->totalDurationMilliseconds,
            'lock_wait_ms' => $metric->lockWaitMilliseconds,
            'recorded_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        $level = match ($metric->event) {
            'failed' => 'warning',
            'retry' => 'notice',
            default => 'info',
        };
        $this->logger->log($level, 'walleting.posting.execution', $metric->context());
    }
}
