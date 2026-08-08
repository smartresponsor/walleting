<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Posting\PostingHealthAssessment;
use App\Posting\PostingHealthStatus;
use App\Posting\PostingSloTrendAssessment;
use App\Service\PostingSloStateService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class PostgreSqlPostingSloStateTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(\Doctrine\DBAL\Platforms\PostgreSQLPlatform::class, $this->connection->getDatabasePlatform());
        $this->connection->executeStatement('DELETE FROM posting_slo_state');
    }

    public function testBreachAndRecoveryRequireConsecutiveEvaluationsBeforePersistedStateChanges(): void
    {
        $service = new PostingSloStateService($this->connection);
        $scope = 'integration-'.Uuid::v7();
        $critical = $this->assessment(PostingHealthStatus::Critical, ['sustained_burn_rate']);
        $healthy = $this->assessment(PostingHealthStatus::Healthy, []);

        $firstBreach = $service->apply($scope, $critical, 2, 3);
        self::assertSame(PostingHealthStatus::Healthy, $firstBreach->currentStatus);
        self::assertSame(PostingHealthStatus::Critical, $firstBreach->pendingStatus);
        self::assertSame(1, $firstBreach->pendingCount);
        self::assertSame(2, $firstBreach->requiredCount);
        self::assertFalse($firstBreach->changed);

        $secondBreach = $service->apply($scope, $critical, 2, 3);
        self::assertSame(PostingHealthStatus::Critical, $secondBreach->currentStatus);
        self::assertNull($secondBreach->pendingStatus);
        self::assertTrue($secondBreach->changed);

        $firstRecovery = $service->apply($scope, $healthy, 2, 3);
        self::assertSame(PostingHealthStatus::Critical, $firstRecovery->currentStatus);
        self::assertSame(PostingHealthStatus::Healthy, $firstRecovery->pendingStatus);
        self::assertSame(1, $firstRecovery->pendingCount);
        self::assertSame(3, $firstRecovery->requiredCount);

        $secondRecovery = $service->apply($scope, $healthy, 2, 3);
        self::assertSame(PostingHealthStatus::Critical, $secondRecovery->currentStatus);
        self::assertSame(2, $secondRecovery->pendingCount);

        $thirdRecovery = $service->apply($scope, $healthy, 2, 3);
        self::assertSame(PostingHealthStatus::Healthy, $thirdRecovery->currentStatus);
        self::assertNull($thirdRecovery->pendingStatus);
        self::assertTrue($thirdRecovery->changed);

        $row = $this->connection->fetchAssociative('SELECT status, pending_status, pending_count, reasons FROM posting_slo_state WHERE scope = ?', [$scope]);
        self::assertIsArray($row);
        self::assertSame('healthy', $row['status']);
        self::assertNull($row['pending_status']);
        self::assertSame(0, (int) $row['pending_count']);
        self::assertSame([], json_decode((string) $row['reasons'], true, 512, JSON_THROW_ON_ERROR));
    }

    public function testDifferentObservedTargetResetsPendingSequence(): void
    {
        $service = new PostingSloStateService($this->connection);
        $scope = 'reset-'.Uuid::v7();

        $critical = $service->apply($scope, $this->assessment(PostingHealthStatus::Critical, ['sustained_critical']), 3, 3);
        self::assertSame(1, $critical->pendingCount);
        self::assertSame(PostingHealthStatus::Critical, $critical->pendingStatus);

        $degraded = $service->apply($scope, $this->assessment(PostingHealthStatus::Degraded, ['short_window_spike']), 3, 3);
        self::assertSame(1, $degraded->pendingCount);
        self::assertSame(PostingHealthStatus::Degraded, $degraded->pendingStatus);
        self::assertSame(PostingHealthStatus::Healthy, $degraded->currentStatus);
    }

    /** @param list<string> $reasons */
    private function assessment(PostingHealthStatus $status, array $reasons): PostingSloTrendAssessment
    {
        $window = new PostingHealthAssessment($status, $reasons);

        return new PostingSloTrendAssessment(
            $status,
            $window,
            $window,
            0.0,
            0.0,
            0.0,
            0.0,
            $reasons,
        );
    }
}
