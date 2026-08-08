<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\OutboxMessage;
use App\Outbox\OutboxMessageHandlerInterface;
use App\Service\OutboxDeadLetterService;
use App\Service\OutboxDispatcher;
use App\Service\OutboxService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PostgreSqlOutboxDeadLetterRequeueTest extends KernelTestCase
{
    private Connection $connection;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $this->entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(\Doctrine\DBAL\Platforms\PostgreSQLPlatform::class, $this->connection->getDatabasePlatform());
        $this->connection->executeStatement('DELETE FROM outbox_requeue_audit');
        $this->connection->executeStatement('DELETE FROM outbox_message');
    }

    public function testDeadMessageCanBeRequeuedWithAuditAndAttemptHistoryPreserved(): void
    {
        $this->enqueue();
        $service = $this->outboxService();
        $handler = new class implements OutboxMessageHandlerInterface {
            public function supports(string $messageType): bool { return 'posting.requeue.test' === $messageType; }
            public function handle(OutboxMessage $message): void { throw new \RuntimeException('persistent failure'); }
        };
        $dispatcher = new OutboxDispatcher($service, [$handler], maxAttempts: 1, baseDelaySeconds: 30, maxDelaySeconds: 30);
        $dispatcher->dispatchBatchReport(1);

        $row = $this->connection->fetchAssociative("SELECT id, status, attempt_count, last_error FROM outbox_message WHERE deduplication_key = 'posting.requeue.test:one'");
        self::assertIsArray($row);
        self::assertSame('dead', $row['status']);
        self::assertSame(1, (int) $row['attempt_count']);

        $this->entityManager->clear();
        $requeued = (new OutboxDeadLetterService($this->entityManager))->requeue((string) $row['id'], 'ops@example.com', 'Transport configuration repaired');

        self::assertSame('failed', $requeued->status()->value);
        self::assertSame(1, $requeued->attemptCount());
        self::assertSame('persistent failure', $requeued->lastError());
        $audit = $this->connection->fetchAssociative('SELECT attempt_count, operator, reason, previous_error FROM outbox_requeue_audit WHERE outbox_message_id = ?', [$row['id']]);
        self::assertIsArray($audit);
        self::assertSame(1, (int) $audit['attempt_count']);
        self::assertSame('ops@example.com', $audit['operator']);
        self::assertSame('Transport configuration repaired', $audit['reason']);
        self::assertSame('persistent failure', $audit['previous_error']);

        $this->entityManager->clear();
        $claimed = $service->claimBatch(1);
        self::assertCount(1, $claimed);
        self::assertSame(2, $claimed[0]->attemptCount());
    }

    public function testRequeuedMessageThatFailsAgainReturnsToDeadWithoutResettingHistory(): void
    {
        $this->enqueue();
        $service = $this->outboxService();
        $handler = new class implements OutboxMessageHandlerInterface {
            public function supports(string $messageType): bool { return 'posting.requeue.test' === $messageType; }
            public function handle(OutboxMessage $message): void { throw new \RuntimeException('still broken'); }
        };
        $dispatcher = new OutboxDispatcher($service, [$handler], maxAttempts: 1, baseDelaySeconds: 30, maxDelaySeconds: 30);
        $dispatcher->dispatchBatchReport(1);
        $id = (string) $this->connection->fetchOne("SELECT id FROM outbox_message WHERE deduplication_key = 'posting.requeue.test:one'");

        $this->entityManager->clear();
        (new OutboxDeadLetterService($this->entityManager))->requeue($id, 'operator-1', 'Retry after repair');
        $this->entityManager->clear();

        $report = $dispatcher->dispatchBatchReport(1);
        self::assertSame(1, $report->dead);
        $row = $this->connection->fetchAssociative('SELECT status, attempt_count, last_error FROM outbox_message WHERE id = ?', [$id]);
        self::assertIsArray($row);
        self::assertSame('dead', $row['status']);
        self::assertSame(2, (int) $row['attempt_count']);
        self::assertSame('still broken', $row['last_error']);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM outbox_requeue_audit WHERE outbox_message_id = ?', [$id]));
    }

    public function testNonDeadMessageCannotBeRequeued(): void
    {
        $this->enqueue();
        $id = (string) $this->connection->fetchOne("SELECT id FROM outbox_message WHERE deduplication_key = 'posting.requeue.test:one'");
        $this->entityManager->clear();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Only dead outbox messages can be requeued.');
        (new OutboxDeadLetterService($this->entityManager))->requeue($id, 'operator-1', 'Invalid attempt');
    }

    private function enqueue(): void
    {
        $this->connection->transactional(function (): void {
            $this->outboxService()->enqueueOperationalDbal('posting.requeue.test', 'posting.requeue.test:one', ['test' => true]);
        });
    }

    private function outboxService(): OutboxService
    {
        return new OutboxService($this->entityManager, $this->connection);
    }
}
