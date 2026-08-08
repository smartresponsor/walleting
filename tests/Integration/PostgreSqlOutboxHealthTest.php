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

final class PostgreSqlOutboxHealthTest extends KernelTestCase
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

    public function testDeadLetterHealthIncludesAgeAndRequeueMetadata(): void
    {
        $service = new OutboxService($this->entityManager, $this->connection);
        $this->connection->transactional(function () use ($service): void {
            $service->enqueueOperationalDbal('posting.health.test', 'posting.health.test:one', ['scope' => 'default']);
        });
        $handler = new class implements OutboxMessageHandlerInterface {
            public function supports(string $messageType): bool { return 'posting.health.test' === $messageType; }
            public function handle(OutboxMessage $message): void { throw new \RuntimeException('downstream unavailable'); }
        };
        $dispatcher = new OutboxDispatcher($service, [$handler], maxAttempts: 1, baseDelaySeconds: 30, maxDelaySeconds: 30);
        $dispatcher->dispatchBatchReport(1);
        $id = (string) $this->connection->fetchOne("SELECT id FROM outbox_message WHERE deduplication_key = 'posting.health.test:one'");

        $this->entityManager->clear();
        (new OutboxDeadLetterService($this->entityManager))->requeue($id, 'operator-health', 'Repair attempted');
        $this->connection->executeStatement("UPDATE outbox_message SET available_at = CURRENT_TIMESTAMP - INTERVAL '1 second' WHERE id = ?", [$id]);
        $this->entityManager->clear();
        $dispatcher->dispatchBatchReport(1);
        $this->connection->executeStatement("UPDATE outbox_message SET created_at = CURRENT_TIMESTAMP - INTERVAL '120 seconds' WHERE id = ?", [$id]);

        $dead = $service->deadLetters(20);

        self::assertCount(1, $dead);
        self::assertSame($id, $dead[0]['id']);
        self::assertSame('posting.health.test', $dead[0]['message_type']);
        self::assertSame(2, (int) $dead[0]['attempt_count']);
        self::assertSame('downstream unavailable', $dead[0]['last_error']);
        self::assertSame(1, (int) $dead[0]['requeue_count']);
        self::assertGreaterThanOrEqual(119, (int) $dead[0]['age_seconds']);
        self::assertNotNull($dead[0]['last_requeued_at']);
    }
}
