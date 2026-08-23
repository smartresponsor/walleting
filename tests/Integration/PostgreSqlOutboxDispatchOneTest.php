<?php

declare(strict_types=1);

namespace App\Walleting\Tests\Integration;

use App\Walleting\Entity\OutboxMessage;
use App\Walleting\Outbox\OutboxMessageHandlerInterface;
use App\Walleting\Service\OutboxDispatcher;
use App\Walleting\Service\OutboxService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PostgreSqlOutboxDispatchOneTest extends KernelTestCase
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

    public function testSelectedDispatchDoesNotClaimOtherDispatchableMessages(): void
    {
        $service = new OutboxService($this->entityManager, $this->connection);
        $this->enqueue($service, 'first');
        $this->enqueue($service, 'selected');
        $firstId = (string) $this->connection->fetchOne("SELECT id FROM outbox_message WHERE deduplication_key = 'posting.dispatch.one:first'");
        $selectedId = (string) $this->connection->fetchOne("SELECT id FROM outbox_message WHERE deduplication_key = 'posting.dispatch.one:selected'");
        $handledIds = [];
        $handler = new class($handledIds) implements OutboxMessageHandlerInterface {
            public function __construct(private array &$handledIds)
            {
            }

            public function supports(string $messageType): bool
            {
                return 'posting.dispatch.one.test' === $messageType;
            }

            public function handle(OutboxMessage $message): void
            {
                $this->handledIds[] = $message->id()->toRfc4122();
            }
        };
        $dispatcher = new OutboxDispatcher($service, [$handler]);

        $report = $dispatcher->dispatchOneById($selectedId);

        self::assertSame(1, $report->claimed);
        self::assertSame(1, $report->dispatched);
        self::assertSame([$selectedId], $handledIds);
        self::assertSame('dispatched', (string) $this->connection->fetchOne('SELECT status FROM outbox_message WHERE id = ?', [$selectedId]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT attempt_count FROM outbox_message WHERE id = ?', [$selectedId]));
        self::assertSame('pending', (string) $this->connection->fetchOne('SELECT status FROM outbox_message WHERE id = ?', [$firstId]));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT attempt_count FROM outbox_message WHERE id = ?', [$firstId]));
    }

    public function testSelectedDispatchUsesNormalFailureAndRetrySemantics(): void
    {
        $service = new OutboxService($this->entityManager, $this->connection);
        $this->enqueue($service, 'failure');
        $id = (string) $this->connection->fetchOne("SELECT id FROM outbox_message WHERE deduplication_key = 'posting.dispatch.one:failure'");
        $handler = new class implements OutboxMessageHandlerInterface {
            public function supports(string $messageType): bool
            {
                return 'posting.dispatch.one.test' === $messageType;
            }

            public function handle(OutboxMessage $message): void
            {
                throw new \RuntimeException('selected delivery failed');
            }
        };
        $dispatcher = new OutboxDispatcher($service, [$handler], maxAttempts: 8, baseDelaySeconds: 30, maxDelaySeconds: 3600);

        $report = $dispatcher->dispatchOneById($id);

        self::assertSame(1, $report->claimed);
        self::assertSame(0, $report->dispatched);
        self::assertSame(1, $report->retryScheduled);
        self::assertSame('failed', (string) $this->connection->fetchOne('SELECT status FROM outbox_message WHERE id = ?', [$id]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT attempt_count FROM outbox_message WHERE id = ?', [$id]));
        self::assertSame('selected delivery failed', (string) $this->connection->fetchOne('SELECT last_error FROM outbox_message WHERE id = ?', [$id]));
    }

    public function testNonDispatchableSelectedMessageIsRejectedWithoutMutation(): void
    {
        $service = new OutboxService($this->entityManager, $this->connection);
        $this->enqueue($service, 'future', new \DateTimeImmutable('+1 hour'));
        $id = (string) $this->connection->fetchOne("SELECT id FROM outbox_message WHERE deduplication_key = 'posting.dispatch.one:future'");
        $dispatcher = new OutboxDispatcher($service, []);

        try {
            $dispatcher->dispatchOneById($id);
            self::fail('Future outbox message must not be dispatchable.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Outbox message is not dispatchable.', $exception->getMessage());
        }
        self::assertSame('pending', (string) $this->connection->fetchOne('SELECT status FROM outbox_message WHERE id = ?', [$id]));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT attempt_count FROM outbox_message WHERE id = ?', [$id]));
    }

    private function enqueue(OutboxService $service, string $suffix, ?\DateTimeImmutable $availableAt = null): void
    {
        $this->connection->transactional(function () use ($service, $suffix, $availableAt): void {
            $service->enqueueOperationalDbal('posting.dispatch.one.test', 'posting.dispatch.one:'.$suffix, ['suffix' => $suffix]);
            if (null !== $availableAt) {
                $this->connection->executeStatement(
                    'UPDATE outbox_message SET available_at = ? WHERE deduplication_key = ?',
                    [$availableAt->format('Y-m-d H:i:s'), 'posting.dispatch.one:'.$suffix],
                );
            }
        });
    }
}
