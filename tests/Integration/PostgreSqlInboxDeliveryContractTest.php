<?php

declare(strict_types=1);

namespace App\Walleting\Tests\Integration;

use App\Walleting\Message\OutboxEvent;
use App\Walleting\MessageHandler\PostingSloTransitionEventHandler;
use App\Walleting\Posting\PostingSloTransitionNotification;
use App\Walleting\Service\InboxService;
use App\Walleting\Service\PostingSloTransitionNotifierInterface;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PostgreSqlInboxDeliveryContractTest extends KernelTestCase
{
    private Connection $connection;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $this->entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(\Doctrine\DBAL\Platforms\PostgreSQLPlatform::class, $this->connection->getDatabasePlatform());
        $this->connection->executeStatement('DELETE FROM inbox_receipt');
    }

    public function testDuplicateDeliveryNotifiesExactlyOnceAndCreatesOneProcessedReceipt(): void
    {
        $notifications = [];
        $handler = new PostingSloTransitionEventHandler(
            new InboxService($this->entityManager, $this->connection),
            new class($notifications) implements PostingSloTransitionNotifierInterface {
                public function __construct(private array &$notifications) {}
                public function notify(PostingSloTransitionNotification $notification): void { $this->notifications[] = $notification; }
            },
        );
        $event = $this->event('duplicate-once');

        $handler($event);
        $handler($event);

        self::assertCount(1, $notifications);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM inbox_receipt'));
        self::assertSame('processed', (string) $this->connection->fetchOne('SELECT status FROM inbox_receipt LIMIT 1'));
    }

    public function testNotifierFailureRollsBackReceiptSoMessengerRetryCanProcessLater(): void
    {
        $inbox = new InboxService($this->entityManager, $this->connection);
        $failing = new PostingSloTransitionEventHandler(
            $inbox,
            new class implements PostingSloTransitionNotifierInterface {
                public function notify(PostingSloTransitionNotification $notification): void { throw new \RuntimeException('notifier unavailable'); }
            },
        );
        $event = $this->event('retry-after-failure');

        try {
            $failing($event);
            self::fail('Notifier failure must propagate to Messenger.');
        } catch (\RuntimeException $exception) {
            self::assertSame('notifier unavailable', $exception->getMessage());
        }
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM inbox_receipt'));

        $notifications = [];
        $retry = new PostingSloTransitionEventHandler(
            $inbox,
            new class($notifications) implements PostingSloTransitionNotifierInterface {
                public function __construct(private array &$notifications) {}
                public function notify(PostingSloTransitionNotification $notification): void { $this->notifications[] = $notification; }
            },
        );
        $retry($event);

        self::assertCount(1, $notifications);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM inbox_receipt'));
        self::assertSame('processed', (string) $this->connection->fetchOne('SELECT status FROM inbox_receipt LIMIT 1'));
    }

    private function event(string $suffix): OutboxEvent
    {
        return new OutboxEvent(
            messageId: '0198-inbox-'.$suffix,
            type: 'posting.slo.state.changed',
            deduplicationKey: 'posting.slo.state.changed:default:'.$suffix,
            payload: [
                'scope' => 'default',
                'revision' => 1,
                'previous_status' => 'healthy',
                'current_status' => 'critical',
                'reasons' => ['sustained_burn_rate'],
                'changed_at' => '2026-08-08 03:30:00',
            ],
            ledgerTransactionId: null,
            providerEventExternalId: null,
        );
    }
}
