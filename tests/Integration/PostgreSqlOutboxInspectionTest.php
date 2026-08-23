<?php

declare(strict_types=1);

namespace App\Walleting\Tests\Integration;

use App\Walleting\Entity\OutboxMessage;
use App\Walleting\Outbox\OutboxMessageHandlerInterface;
use App\Walleting\Service\OutboxDeadLetterService;
use App\Walleting\Service\OutboxDispatcher;
use App\Walleting\Service\OutboxService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PostgreSqlOutboxInspectionTest extends KernelTestCase
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

    public function testInspectionReturnsMessagePayloadAndOrderedRequeueHistory(): void
    {
        $service = new OutboxService($this->entityManager, $this->connection);
        $this->connection->transactional(function () use ($service): void {
            $service->enqueueOperationalDbal('posting.inspect.test', 'posting.inspect.test:one', ['scope' => 'default', 'revision' => 7]);
        });
        $handler = new class implements OutboxMessageHandlerInterface {
            public function supports(string $messageType): bool
            {
                return 'posting.inspect.test' === $messageType;
            }

            public function handle(OutboxMessage $message): void
            {
                throw new \RuntimeException('downstream unavailable');
            }
        };
        $dispatcher = new OutboxDispatcher($service, [$handler], maxAttempts: 1, baseDelaySeconds: 30, maxDelaySeconds: 30);
        $dispatcher->dispatchBatchReport(1);
        $id = (string) $this->connection->fetchOne("SELECT id FROM outbox_message WHERE deduplication_key = 'posting.inspect.test:one'");

        $this->entityManager->clear();
        (new OutboxDeadLetterService($this->entityManager))->requeue($id, 'operator-a', 'First repair');
        $this->connection->executeStatement('UPDATE outbox_message SET available_at = CURRENT_TIMESTAMP - INTERVAL \'1 second\' WHERE id = ?', [$id]);
        $this->entityManager->clear();
        $dispatcher->dispatchBatchReport(1);
        $this->entityManager->clear();
        (new OutboxDeadLetterService($this->entityManager))->requeue($id, 'operator-b', 'Second repair');
        $this->entityManager->clear();

        $detail = $service->inspect($id);

        self::assertSame($id, $detail['id']);
        self::assertSame('posting.inspect.test', $detail['message_type']);
        self::assertSame('failed', $detail['status']);
        self::assertSame(2, (int) $detail['attempt_count']);
        self::assertSame(['scope' => 'default', 'revision' => 7], $detail['payload']);
        self::assertCount(2, $detail['requeue_history']);
        self::assertSame('operator-a', $detail['requeue_history'][0]['operator']);
        self::assertSame('First repair', $detail['requeue_history'][0]['reason']);
        self::assertSame(1, (int) $detail['requeue_history'][0]['attempt_count']);
        self::assertSame('operator-b', $detail['requeue_history'][1]['operator']);
        self::assertSame('Second repair', $detail['requeue_history'][1]['reason']);
        self::assertSame(2, (int) $detail['requeue_history'][1]['attempt_count']);
        self::assertSame('downstream unavailable', $detail['requeue_history'][1]['previous_error']);
    }

    public function testInspectionRejectsUnknownMessage(): void
    {
        $service = new OutboxService($this->entityManager, $this->connection);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Outbox message was not found.');
        $service->inspect('019fe100-0000-7000-8000-000000000001');
    }
}
