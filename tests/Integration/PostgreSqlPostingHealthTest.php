<?php

declare(strict_types=1);

namespace App\Walleting\Tests\Integration;

use App\Walleting\Posting\PostingExecutionMetric;
use App\Walleting\Service\DatabasePostingTelemetry;
use App\Walleting\Service\PostingHealthService;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class PostgreSqlPostingHealthTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(\Doctrine\DBAL\Platforms\PostgreSQLPlatform::class, $this->connection->getDatabasePlatform());
        $this->connection->executeStatement('DELETE FROM posting_metric_sample');
    }

    public function testWindowAggregationCalculatesRetryFailureP95AndContentionCounters(): void
    {
        $telemetry = new DatabasePostingTelemetry($this->connection, new NullLogger());
        foreach ([
            new PostingExecutionMetric('completed', 'credit', 1, 0, null, 100, 100, null),
            new PostingExecutionMetric('retry', 'transfer', 1, 1, 'lock_timeout', 100, 100, 100),
            new PostingExecutionMetric('completed', 'transfer', 2, 1, null, 100, 200, null),
            new PostingExecutionMetric('failed', 'debit', 1, 0, null, 300, 300, null),
            new PostingExecutionMetric('retry', 'transfer', 1, 1, 'deadlock', 100, 100, null),
            new PostingExecutionMetric('failed', 'transfer', 2, 1, 'deadlock', 400, 400, null),
        ] as $metric) {
            $telemetry->record($metric);
        }

        $snapshot = (new PostingHealthService($this->connection))->snapshot(3600);

        self::assertSame(4, $snapshot->executionCount);
        self::assertSame(2, $snapshot->completedCount);
        self::assertSame(2, $snapshot->failedCount);
        self::assertSame(2, $snapshot->retriedExecutionCount);
        self::assertSame(2, $snapshot->retryEventCount);
        self::assertSame(0.5, $snapshot->retryRate);
        self::assertSame(0.5, $snapshot->failureRate);
        self::assertSame(385, $snapshot->p95LatencyMilliseconds);
        self::assertSame(1, $snapshot->lockTimeoutCount);
        self::assertSame(2, $snapshot->deadlockCount);
    }

    public function testRetentionCleanupDeletesOnlyOldSamplesInBoundedBatch(): void
    {
        $this->insertSample('-40 days');
        $this->insertSample('-40 days');
        $this->insertSample('now');
        $service = new PostingHealthService($this->connection);

        self::assertSame(1, $service->cleanup(30, 1));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM posting_metric_sample WHERE recorded_at < (CURRENT_TIMESTAMP AT TIME ZONE 'UTC') - INTERVAL '30 days'"));
        self::assertSame(1, $service->cleanup(30, 500));
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM posting_metric_sample WHERE recorded_at < (CURRENT_TIMESTAMP AT TIME ZONE 'UTC') - INTERVAL '30 days'"));
    }

    public function testPostingHealthUsesUtcWallClockIndependentOfDatabaseSessionTimezone(): void
    {
        $this->connection->executeStatement("SET TIME ZONE 'Asia/Tokyo'");
        try {
            $this->insertSample('-2 hours');
            $this->insertSample('now');

            $snapshot = (new PostingHealthService($this->connection))->snapshot(3600);

            self::assertSame(1, $snapshot->executionCount);
            self::assertSame(1, $snapshot->completedCount);
        } finally {
            $this->connection->executeStatement('RESET TIME ZONE');
        }
    }

    private function insertSample(string $when): void
    {
        $recordedAt = new \DateTimeImmutable($when);
        $this->connection->insert('posting_metric_sample', [
            'id' => Uuid::v7()->toRfc4122(),
            'event' => 'completed',
            'transaction_type' => 'credit',
            'attempt' => 1,
            'retry_count' => 0,
            'retry_reason' => null,
            'attempt_duration_ms' => 10,
            'total_duration_ms' => 10,
            'lock_wait_ms' => null,
            'recorded_at' => $recordedAt->format('Y-m-d H:i:s'),
        ]);
    }
}
