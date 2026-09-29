<?php

declare(strict_types=1);

namespace App\Walleting\Tests\Integration;

use App\Walleting\Service\WalletOutboxService;
use App\Walleting\Service\WalletPostingSloStateService;
use App\Walleting\ValueObject\Posting\WalletPostingHealthAssessment;
use App\Walleting\ValueObject\Posting\WalletPostingHealthStatus;
use App\Walleting\ValueObject\Posting\WalletPostingSloTrendAssessment;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class PostgreSqlPostingSloStateTest extends KernelTestCase
{
    private Connection $connection;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $this->entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(\Doctrine\DBAL\Platforms\PostgreSQLPlatform::class, $this->connection->getDatabasePlatform());
        $this->connection->executeStatement("DELETE FROM wallet_outbox_message WHERE message_type = 'posting.slo.state.changed'");
        $this->connection->executeStatement('DELETE FROM posting_slo_state');
    }

    public function testBreachAndRecoveryRequireConsecutiveEvaluationsBeforePersistedStateChanges(): void
    {
        $service = new WalletPostingSloStateService($this->connection, new WalletOutboxService($this->entityManager, $this->connection));
        $scope = 'integration-'.Uuid::v7();
        $critical = $this->assessment(WalletPostingHealthStatus::Critical, ['sustained_burn_rate']);
        $healthy = $this->assessment(WalletPostingHealthStatus::Healthy, []);

        $firstBreach = $service->apply($scope, $critical, 2, 3);
        self::assertSame(WalletPostingHealthStatus::Healthy, $firstBreach->currentStatus);
        self::assertSame(WalletPostingHealthStatus::Critical, $firstBreach->pendingStatus);
        self::assertSame(1, $firstBreach->pendingCount);
        self::assertSame(2, $firstBreach->requiredCount);
        self::assertFalse($firstBreach->changed);
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM wallet_outbox_message WHERE message_type = 'posting.slo.state.changed'"));

        $secondBreach = $service->apply($scope, $critical, 2, 3);
        self::assertSame(WalletPostingHealthStatus::Critical, $secondBreach->currentStatus);
        self::assertNull($secondBreach->pendingStatus);
        self::assertTrue($secondBreach->changed);

        $firstRecovery = $service->apply($scope, $healthy, 2, 3);
        self::assertSame(WalletPostingHealthStatus::Critical, $firstRecovery->currentStatus);
        self::assertSame(WalletPostingHealthStatus::Healthy, $firstRecovery->pendingStatus);
        self::assertSame(1, $firstRecovery->pendingCount);
        self::assertSame(3, $firstRecovery->requiredCount);

        $secondRecovery = $service->apply($scope, $healthy, 2, 3);
        self::assertSame(WalletPostingHealthStatus::Critical, $secondRecovery->currentStatus);
        self::assertSame(2, $secondRecovery->pendingCount);

        $thirdRecovery = $service->apply($scope, $healthy, 2, 3);
        self::assertSame(WalletPostingHealthStatus::Healthy, $thirdRecovery->currentStatus);
        self::assertNull($thirdRecovery->pendingStatus);
        self::assertTrue($thirdRecovery->changed);

        $row = $this->connection->fetchAssociative('SELECT status, pending_status, pending_count, revision, reasons FROM posting_slo_state WHERE scope = ?', [$scope]);
        self::assertIsArray($row);
        self::assertSame('healthy', $row['status']);
        self::assertNull($row['pending_status']);
        self::assertSame(0, (int) $row['pending_count']);
        self::assertSame(2, (int) $row['revision']);
        self::assertSame([], json_decode((string) $row['reasons'], true, 512, JSON_THROW_ON_ERROR));
        self::assertSame(2, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM wallet_outbox_message WHERE message_type = 'posting.slo.state.changed'"));
        $alerts = $this->connection->fetchAllAssociative("SELECT deduplication_key, ledger_transaction_id, provider_event_id, payload FROM wallet_outbox_message WHERE message_type = 'posting.slo.state.changed' ORDER BY created_at, id");
        self::assertNull($alerts[0]['ledger_transaction_id']);
        self::assertNull($alerts[0]['provider_event_id']);
        self::assertSame('posting.slo.state.changed:'.$scope.':1', $alerts[0]['deduplication_key']);
        self::assertSame('posting.slo.state.changed:'.$scope.':2', $alerts[1]['deduplication_key']);
        self::assertSame('critical', json_decode((string) $alerts[0]['payload'], true, 512, JSON_THROW_ON_ERROR)['current_status']);
        self::assertSame('healthy', json_decode((string) $alerts[1]['payload'], true, 512, JSON_THROW_ON_ERROR)['current_status']);
    }

    public function testDifferentObservedTargetResetsPendingSequence(): void
    {
        $service = new WalletPostingSloStateService($this->connection, new WalletOutboxService($this->entityManager, $this->connection));
        $scope = 'reset-'.Uuid::v7();

        $critical = $service->apply($scope, $this->assessment(WalletPostingHealthStatus::Critical, ['sustained_critical']), 3, 3);
        self::assertSame(1, $critical->pendingCount);
        self::assertSame(WalletPostingHealthStatus::Critical, $critical->pendingStatus);

        $degraded = $service->apply($scope, $this->assessment(WalletPostingHealthStatus::Degraded, ['short_window_spike']), 3, 3);
        self::assertSame(1, $degraded->pendingCount);
        self::assertSame(WalletPostingHealthStatus::Degraded, $degraded->pendingStatus);
        self::assertSame(WalletPostingHealthStatus::Healthy, $degraded->currentStatus);
    }

    /** @param list<string> $reasons */
    private function assessment(WalletPostingHealthStatus $status, array $reasons): WalletPostingSloTrendAssessment
    {
        $window = new WalletPostingHealthAssessment($status, $reasons);

        return new WalletPostingSloTrendAssessment(
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
