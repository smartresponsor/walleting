<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Account;
use App\Service\StatementQueryService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class PostgreSqlStatementQueryTest extends KernelTestCase
{
    private Connection $connection;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $this->entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertSame('postgresql', $this->connection->getDatabasePlatform()->getName());
    }

    public function testStatementGroupingRunningBalanceDateFilteringAndCursorContinuity(): void
    {
        [$accountId, $counterpartyId] = $this->seedAccounts();

        $tx1 = $this->seedTransaction('2026-08-07 10:01:00', 'funding', [
            [$accountId, 700],
            [$accountId, 300],
            [$counterpartyId, -1000],
        ]);
        $tx2 = $this->seedTransaction('2026-08-07 10:02:00', 'withdrawal', [
            [$accountId, -200],
            [$counterpartyId, 200],
        ]);
        $tx3 = $this->seedTransaction('2026-08-07 10:03:00', 'funding', [
            [$accountId, 300],
            [$counterpartyId, -300],
        ]);

        $account = $this->entityManager->find(Account::class, $accountId);
        self::assertInstanceOf(Account::class, $account);
        $service = new StatementQueryService($this->connection);

        $firstPage = $service->statement($account, 2);
        self::assertCount(2, $firstPage->items);
        self::assertSame($tx3, $firstPage->items[0]->transactionId);
        self::assertSame(300, $firstPage->items[0]->amountMinor);
        self::assertSame(1100, $firstPage->items[0]->runningBalanceMinor);
        self::assertSame($tx2, $firstPage->items[1]->transactionId);
        self::assertSame(-200, $firstPage->items[1]->amountMinor);
        self::assertSame(800, $firstPage->items[1]->runningBalanceMinor);
        self::assertNotNull($firstPage->nextCursor);

        $secondPage = $service->statement($account, 2, $firstPage->nextCursor);
        self::assertCount(1, $secondPage->items);
        self::assertSame($tx1, $secondPage->items[0]->transactionId);
        self::assertSame(1000, $secondPage->items[0]->amountMinor);
        self::assertSame(1000, $secondPage->items[0]->runningBalanceMinor);
        self::assertNull($secondPage->nextCursor);

        $range = $service->statement(
            $account,
            from: new \DateTimeImmutable('2026-08-07 10:01:30'),
            to: new \DateTimeImmutable('2026-08-07 10:03:00'),
        );
        self::assertCount(2, $range->items);
        self::assertSame([$tx3, $tx2], array_map(static fn ($item): string => $item->transactionId, $range->items));
        self::assertSame([1100, 800], array_map(static fn ($item): int => $item->runningBalanceMinor, $range->items));
        self::assertSame('funding', $range->items[0]->operation());
        self::assertSame($counterpartyId, $range->items[0]->counterparties[0]['account_id']);
    }

    /** @return array{string, string} */
    private function seedAccounts(): array
    {
        $walletId = Uuid::v7()->toRfc4122();
        $accountId = Uuid::v7()->toRfc4122();
        $counterpartyId = Uuid::v7()->toRfc4122();
        $now = '2026-08-07 10:00:00';

        $this->connection->insert('wallet', [
            'id' => $walletId,
            'owner_type' => 'statement-integration',
            'owner_id' => Uuid::v7()->toRfc4122(),
            'status' => 'active',
            'created_at' => $now,
        ]);
        $this->connection->insert('account', [
            'id' => $accountId,
            'wallet_id' => $walletId,
            'code' => 'cash',
            'currency' => 'USD',
            'category' => 'asset',
            'allow_negative' => false,
            'created_at' => $now,
        ]);
        $this->connection->insert('account', [
            'id' => $counterpartyId,
            'wallet_id' => $walletId,
            'code' => 'clearing',
            'currency' => 'USD',
            'category' => 'clearing',
            'allow_negative' => true,
            'created_at' => $now,
        ]);

        return [$accountId, $counterpartyId];
    }

    /** @param list<array{string, int}> $postings */
    private function seedTransaction(string $postedAt, string $operation, array $postings): string
    {
        $transactionId = Uuid::v7()->toRfc4122();
        $idempotencyKey = 'statement-'.Uuid::v7();

        $this->connection->beginTransaction();
        try {
            $this->connection->insert('ledger_transaction', [
                'id' => $transactionId,
                'type' => 'credit',
                'status' => 'posted',
                'idempotency_key' => $idempotencyKey,
                'request_hash' => hash('sha256', $idempotencyKey),
                'metadata' => json_encode(['operation' => $operation], JSON_THROW_ON_ERROR),
                'created_at' => $postedAt,
                'posted_at' => $postedAt,
            ]);

            foreach ($postings as $index => [$accountId, $amountMinor]) {
                $this->connection->insert('posting', [
                    'id' => Uuid::v7()->toRfc4122(),
                    'transaction_id' => $transactionId,
                    'account_id' => $accountId,
                    'amount_minor' => $amountMinor,
                    'currency' => 'USD',
                    'sequence' => $index + 1,
                    'created_at' => $postedAt,
                ]);
            }

            $this->connection->commit();
        } catch (\Throwable $exception) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            throw $exception;
        }

        return $transactionId;
    }
}
