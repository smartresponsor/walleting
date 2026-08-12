<?php

declare(strict_types=1);

namespace App\Walleting\Tests\Integration;

use App\Walleting\Entity\OutboxMessage;
use App\Walleting\Enum\OutboxMessageStatus;
use App\Walleting\Outbox\OutboxMessageHandlerInterface;
use App\Walleting\Service\OutboxDispatcher;
use App\Walleting\Service\OutboxService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PostgreSqlOutboxDispatcherAcknowledgmentTest extends KernelTestCase
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

    public function testSuccessfulHandlerAcknowledgesOnlyAfterHandleReturns(): void
    {
        $service = $this->outboxService();
        $this->enqueue('success');
        $handledStatus = null;
        $handler = new class($handledStatus) implements OutboxMessageHandlerInterface {
            public function __construct(private mixed &$handledStatus) {}
            public function supports(string $messageType): bool { return 'posting.dispatch.ack.test' === $messageType; }
            public function handle(OutboxMessage $message): void { $this->handledStatus = $message->status(); }
        };
        $dispatcher = new OutboxDispatcher($service, [$handler]);

        $report = $dispatcher->dispatchBatchReport(1);

        self::assertSame(OutboxMessageStatus::Claimed, $handledStatus);
        self::assertSame(1, $report->dispatched);
        self::assertSame(0, $report->retryScheduled);
        self::assertSame('dispatched', (string) $this->connection->fetchOne("SELECT status FROM outbox_message WHERE deduplication_key = 'posting.dispatch.ack.test:success'"));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT attempt_count FROM outbox_message WHERE deduplication_key = 'posting.dispatch.ack.test:success'"));
        self::assertNull($this->connection->fetchOne("SELECT last_error FROM outbox_message WHERE deduplication_key = 'posting.dispatch.ack.test:success'"));
    }

    public function testHandlerFailureLeavesMessageRetryableAndNeverMarksItDispatched(): void
    {
        $service = $this->outboxService();
        $this->enqueue('failure');
        $handler = new class implements OutboxMessageHandlerInterface {
            public function supports(string $messageType): bool { return 'posting.dispatch.ack.test' === $messageType; }
            public function handle(OutboxMessage $message): void { throw new \RuntimeException('transport unavailable'); }
        };
        $dispatcher = new OutboxDispatcher($service, [$handler], maxAttempts: 8, baseDelaySeconds: 30, maxDelaySeconds: 3600);

        $before = new \DateTimeImmutable();
        $report = $dispatcher->dispatchBatchReport(1);

        self::assertSame(0, $report->dispatched);
        self::assertSame(1, $report->retryScheduled);
        self::assertSame(0, $report->dead);
        $row = $this->connection->fetchAssociative("SELECT status, attempt_count, last_error, dispatched_at, available_at FROM outbox_message WHERE deduplication_key = 'posting.dispatch.ack.test:failure'");
        self::assertIsArray($row);
        self::assertSame('failed', $row['status']);
        self::assertSame(1, (int) $row['attempt_count']);
        self::assertSame('transport unavailable', $row['last_error']);
        self::assertNull($row['dispatched_at']);
        self::assertGreaterThanOrEqual($before->modify('+29 seconds')->getTimestamp(), (new \DateTimeImmutable((string) $row['available_at']))->getTimestamp());
    }

    public function testRetryExhaustionTransitionsToDeadAndDeadMessageIsNeverClaimedAgain(): void
    {
        $service = $this->outboxService();
        $this->enqueue('exhaustion');
        $handler = new class implements OutboxMessageHandlerInterface {
            public function supports(string $messageType): bool { return 'posting.dispatch.ack.test' === $messageType; }
            public function handle(OutboxMessage $message): void { throw new \RuntimeException('persistent transport failure'); }
        };
        $dispatcher = new OutboxDispatcher($service, [$handler], maxAttempts: 8, baseDelaySeconds: 30, maxDelaySeconds: 3600);
        $expectedDelays = [30, 60, 120, 240, 480, 960, 1920];

        foreach ($expectedDelays as $index => $expectedDelay) {
            $before = new \DateTimeImmutable();
            $report = $dispatcher->dispatchBatchReport(1);
            self::assertSame(1, $report->retryScheduled, sprintf('Attempt %d must schedule a retry.', $index + 1));
            self::assertSame(0, $report->dead);

            $row = $this->connection->fetchAssociative("SELECT status, attempt_count, available_at, dispatched_at FROM outbox_message WHERE deduplication_key = 'posting.dispatch.ack.test:exhaustion'");
            self::assertIsArray($row);
            self::assertSame('failed', $row['status']);
            self::assertSame($index + 1, (int) $row['attempt_count']);
            self::assertNull($row['dispatched_at']);
            $availableAt = new \DateTimeImmutable((string) $row['available_at']);
            self::assertGreaterThanOrEqual($before->modify(sprintf('+%d seconds', $expectedDelay - 1))->getTimestamp(), $availableAt->getTimestamp());
            self::assertLessThanOrEqual($before->modify(sprintf('+%d seconds', $expectedDelay + 2))->getTimestamp(), $availableAt->getTimestamp());

            $this->connection->executeStatement("UPDATE outbox_message SET available_at = CURRENT_TIMESTAMP - INTERVAL '1 second' WHERE deduplication_key = 'posting.dispatch.ack.test:exhaustion'");
            $this->entityManager->clear();
        }

        $terminal = $dispatcher->dispatchBatchReport(1);
        self::assertSame(0, $terminal->retryScheduled);
        self::assertSame(1, $terminal->dead);
        $row = $this->connection->fetchAssociative("SELECT status, attempt_count, last_error, dispatched_at FROM outbox_message WHERE deduplication_key = 'posting.dispatch.ack.test:exhaustion'");
        self::assertIsArray($row);
        self::assertSame('dead', $row['status']);
        self::assertSame(8, (int) $row['attempt_count']);
        self::assertSame('persistent transport failure', $row['last_error']);
        self::assertNull($row['dispatched_at']);

        $this->entityManager->clear();
        self::assertSame([], $service->claimBatch(1));
        self::assertSame(0, $dispatcher->dispatchBatchReport(1)->claimed);
    }

    public function testRetryBackoffIsCappedAtConfiguredMaximum(): void
    {
        $service = $this->outboxService();
        $this->enqueue('backoff-cap');
        $handler = new class implements OutboxMessageHandlerInterface {
            public function supports(string $messageType): bool { return 'posting.dispatch.ack.test' === $messageType; }
            public function handle(OutboxMessage $message): void { throw new \RuntimeException('temporary failure'); }
        };
        $dispatcher = new OutboxDispatcher($service, [$handler], maxAttempts: 8, baseDelaySeconds: 30, maxDelaySeconds: 100);
        $expectedDelays = [30, 60, 100, 100];

        foreach ($expectedDelays as $index => $expectedDelay) {
            $before = new \DateTimeImmutable();
            $report = $dispatcher->dispatchBatchReport(1);
            self::assertSame(1, $report->retryScheduled);
            $availableAt = new \DateTimeImmutable((string) $this->connection->fetchOne("SELECT available_at FROM outbox_message WHERE deduplication_key = 'posting.dispatch.ack.test:backoff-cap'"));
            self::assertGreaterThanOrEqual($before->modify(sprintf('+%d seconds', $expectedDelay - 1))->getTimestamp(), $availableAt->getTimestamp(), sprintf('Attempt %d must respect bounded backoff.', $index + 1));
            self::assertLessThanOrEqual($before->modify(sprintf('+%d seconds', $expectedDelay + 2))->getTimestamp(), $availableAt->getTimestamp());
            $this->connection->executeStatement("UPDATE outbox_message SET available_at = CURRENT_TIMESTAMP - INTERVAL '1 second' WHERE deduplication_key = 'posting.dispatch.ack.test:backoff-cap'");
            $this->entityManager->clear();
        }
    }

    private function enqueue(string $suffix): void
    {
        $this->connection->transactional(function () use ($suffix): void {
            $this->outboxService()->enqueueOperationalDbal(
                'posting.dispatch.ack.test',
                'posting.dispatch.ack.test:'.$suffix,
                ['suffix' => $suffix],
            );
        });
    }

    private function outboxService(): OutboxService
    {
        return new OutboxService($this->entityManager, $this->connection);
    }
}
