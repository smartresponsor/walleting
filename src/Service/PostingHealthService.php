<?php

declare(strict_types=1);

namespace App\Service;

use App\Posting\PostingHealthSnapshot;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final readonly class PostingHealthService
{
    public function __construct(private Connection $connection)
    {
    }

    public function snapshot(int $windowSeconds): PostingHealthSnapshot
    {
        if ($windowSeconds < 60 || $windowSeconds > 604800) {
            throw new \InvalidArgumentException('Posting health window must be between 60 and 604800 seconds.');
        }

        $row = $this->connection->fetchAssociative(
            <<<'SQL'
SELECT
    COUNT(*) FILTER (WHERE event IN ('completed', 'failed')) AS execution_count,
    COUNT(*) FILTER (WHERE event = 'completed') AS completed_count,
    COUNT(*) FILTER (WHERE event = 'failed') AS failed_count,
    COUNT(*) FILTER (WHERE event IN ('completed', 'failed') AND retry_count > 0) AS retried_execution_count,
    COUNT(*) FILTER (WHERE event = 'retry') AS retry_event_count,
    percentile_cont(0.95) WITHIN GROUP (ORDER BY total_duration_ms) FILTER (WHERE event IN ('completed', 'failed')) AS p95_latency_ms,
    COUNT(*) FILTER (WHERE retry_reason = 'lock_timeout') AS lock_timeout_count,
    COUNT(*) FILTER (WHERE retry_reason = 'deadlock') AS deadlock_count
FROM posting_metric_sample
WHERE recorded_at >= CURRENT_TIMESTAMP - (? * INTERVAL '1 second')
SQL,
            [$windowSeconds],
            [ParameterType::INTEGER],
        );
        if (false === $row) {
            throw new \RuntimeException('Posting health aggregation returned no result.');
        }

        $executionCount = (int) $row['execution_count'];
        $failedCount = (int) $row['failed_count'];
        $retriedExecutionCount = (int) $row['retried_execution_count'];

        return new PostingHealthSnapshot(
            $windowSeconds,
            $executionCount,
            (int) $row['completed_count'],
            $failedCount,
            $retriedExecutionCount,
            (int) $row['retry_event_count'],
            0 === $executionCount ? 0.0 : $retriedExecutionCount / $executionCount,
            0 === $executionCount ? 0.0 : $failedCount / $executionCount,
            null === $row['p95_latency_ms'] ? null : (int) round((float) $row['p95_latency_ms']),
            (int) $row['lock_timeout_count'],
            (int) $row['deadlock_count'],
        );
    }

    public function cleanup(int $retentionDays, int $limit = 500): int
    {
        if ($retentionDays < 1 || $retentionDays > 3650 || $limit < 1 || $limit > 5000) {
            throw new \InvalidArgumentException('Posting metric retention bounds are invalid.');
        }

        return $this->connection->executeStatement(
            "DELETE FROM posting_metric_sample WHERE id IN (SELECT id FROM posting_metric_sample WHERE recorded_at < CURRENT_TIMESTAMP - (? * INTERVAL '1 day') ORDER BY recorded_at, id LIMIT ?)",
            [$retentionDays, $limit],
            [ParameterType::INTEGER, ParameterType::INTEGER],
        );
    }
}
