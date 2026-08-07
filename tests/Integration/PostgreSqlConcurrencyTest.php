<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Service\OutboxService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\ParameterType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class PostgreSqlConcurrencyTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(\Doctrine\DBAL\Platforms\PostgreSQLPlatform::class, $this->connection->getDatabasePlatform());
    }

    public function testConcurrentSpendSerializesOnAccountBalanceAndRejectsRetryAfterWinnerCommits(): void
    {
        [, $assetAccount, $clearingAccount] = $this->seedWalletAndAccounts();
        $this->seedBalancedTransaction($assetAccount, $clearingAccount, 1000);

        $winner = DriverManager::getConnection($this->connection->getParams());
        $loser = DriverManager::getConnection($this->connection->getParams());

        try {
            $winner->beginTransaction();
            $winnerTx = $this->insertTransaction($winner, 'debit', 'winner-'.Uuid::v7());
            $this->insertPosting($winner, $winnerTx, $assetAccount, -700, 1);
            $this->insertPosting($winner, $winnerTx, $clearingAccount, 700, 2);

            $loser->beginTransaction();
            $loser->executeStatement("SET LOCAL lock_timeout = '250ms'");
            $loserTx = $this->insertTransaction($loser, 'debit', 'loser-'.Uuid::v7());
            try {
                $this->insertPosting($loser, $loserTx, $assetAccount, -700, 1);
                self::fail('Concurrent spend must wait on the account balance row lock.');
            } catch (Exception $exception) {
                self::assertStringContainsString('lock timeout', strtolower($exception->getMessage()));
            }
            if ($loser->isTransactionActive()) {
                $loser->rollBack();
            }

            $winner->commit();
            self::assertSame(300, (int) $this->connection->fetchOne('SELECT balance_minor FROM account_balance WHERE account_id = ?', [$assetAccount]));

            $loser->beginTransaction();
            $retryTx = $this->insertTransaction($loser, 'debit', 'retry-'.Uuid::v7());
            try {
                $this->insertPosting($loser, $retryTx, $assetAccount, -700, 1);
                self::fail('Spend retry must reject overdraft after the winning transaction commits.');
            } catch (Exception $exception) {
                self::assertStringContainsString('insufficient available balance', $exception->getMessage());
            }
        } finally {
            foreach ([$winner, $loser] as $connection) {
                if ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }
                $connection->close();
            }
        }
    }

    public function testSkipLockedClaimQueryExcludesRowsLockedByAnotherWorker(): void
    {
        $transactionId = $this->seedReferenceTransaction();
        [$firstId, $secondId] = $this->seedPendingOutboxMessages($transactionId);
        $workerA = DriverManager::getConnection($this->connection->getParams());
        $workerB = DriverManager::getConnection($this->connection->getParams());

        try {
            $workerA->beginTransaction();
            self::assertSame($firstId, (string) $workerA->fetchOne("SELECT id FROM outbox_message WHERE status = 'pending' ORDER BY created_at, id FOR UPDATE LIMIT 1"));

            $workerB->beginTransaction();
            $claimed = $workerB->fetchFirstColumn(
                "SELECT id FROM outbox_message WHERE status IN ('pending', 'failed') AND available_at <= CURRENT_TIMESTAMP ORDER BY created_at, id FOR UPDATE SKIP LOCKED LIMIT 10",
            );

            self::assertContains($secondId, array_map('strval', $claimed));
            self::assertNotContains($firstId, array_map('strval', $claimed));
        } finally {
            foreach ([$workerA, $workerB] as $connection) {
                if ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }
                $connection->close();
            }
        }
    }

    public function testStaleClaimRecoverySkipsLockedReceiptThenRecoversItAfterUnlock(): void
    {
        $transactionId = $this->seedReferenceTransaction();
        [$firstId, $secondId] = $this->seedClaimedOutboxMessages($transactionId);
        $locker = DriverManager::getConnection($this->connection->getParams());
        $outboxService = self::getContainer()->get(OutboxService::class);
        self::assertInstanceOf(OutboxService::class, $outboxService);

        try {
            $locker->beginTransaction();
            self::assertSame($firstId, (string) $locker->fetchOne('SELECT id FROM outbox_message WHERE id = ? FOR UPDATE', [$firstId]));

            self::assertSame(1, $outboxService->recoverStaleClaims(1, 10));
            self::assertSame('claimed', (string) $this->connection->fetchOne('SELECT status FROM outbox_message WHERE id = ?', [$firstId]));
            self::assertSame('failed', (string) $this->connection->fetchOne('SELECT status FROM outbox_message WHERE id = ?', [$secondId]));

            $locker->commit();
            self::assertSame(1, $outboxService->recoverStaleClaims(1, 10));
            self::assertSame('failed', (string) $this->connection->fetchOne('SELECT status FROM outbox_message WHERE id = ?', [$firstId]));
            self::assertSame('Claim lease expired before acknowledgement.', (string) $this->connection->fetchOne('SELECT last_error FROM outbox_message WHERE id = ?', [$firstId]));
        } finally {
            if ($locker->isTransactionActive()) {
                $locker->rollBack();
            }
            $locker->close();
        }
    }

    /** @return array{string, string, string} */
    private function seedWalletAndAccounts(): array
    {
        $walletId = Uuid::v7()->toRfc4122();
        $assetAccount = Uuid::v7()->toRfc4122();
        $clearingAccount = Uuid::v7()->toRfc4122();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->connection->insert('wallet', [
            'id' => $walletId,
            'owner_type' => 'concurrency',
            'owner_id' => Uuid::v7()->toRfc4122(),
            'status' => 'active',
            'created_at' => $now,
        ]);
        $this->connection->insert('account', [
            'id' => $assetAccount,
            'wallet_id' => $walletId,
            'code' => 'asset-'.substr($assetAccount, 0, 8),
            'currency' => 'USD',
            'category' => 'asset',
            'allow_negative' => false,
            'created_at' => $now,
        ], ['allow_negative' => ParameterType::BOOLEAN]);
        $this->connection->insert('account', [
            'id' => $clearingAccount,
            'wallet_id' => $walletId,
            'code' => 'clearing-'.substr($clearingAccount, 0, 8),
            'currency' => 'USD',
            'category' => 'clearing',
            'allow_negative' => true,
            'created_at' => $now,
        ], ['allow_negative' => ParameterType::BOOLEAN]);

        return [$walletId, $assetAccount, $clearingAccount];
    }

    private function seedBalancedTransaction(string $assetAccount, string $clearingAccount, int $amountMinor): string
    {
        $this->connection->beginTransaction();
        $transactionId = $this->insertTransaction($this->connection, 'credit', 'seed-'.Uuid::v7());
        $this->insertPosting($this->connection, $transactionId, $assetAccount, $amountMinor, 1);
        $this->insertPosting($this->connection, $transactionId, $clearingAccount, -$amountMinor, 2);
        $this->connection->commit();

        return $transactionId;
    }

    private function seedReferenceTransaction(): string
    {
        [, $assetAccount, $clearingAccount] = $this->seedWalletAndAccounts();

        return $this->seedBalancedTransaction($assetAccount, $clearingAccount, 100);
    }

    /** @return array{string, string} */
    private function seedPendingOutboxMessages(string $transactionId): array
    {
        $first = Uuid::v7()->toRfc4122();
        usleep(1000);
        $second = Uuid::v7()->toRfc4122();
        $now = new \DateTimeImmutable('-10 seconds');
        $this->insertOutbox($first, $transactionId, 'pending', $now, null, null, 0);
        $this->insertOutbox($second, $transactionId, 'pending', $now->modify('+1 second'), null, null, 0);

        return [$first, $second];
    }

    /** @return array{string, string} */
    private function seedClaimedOutboxMessages(string $transactionId): array
    {
        $first = Uuid::v7()->toRfc4122();
        $second = Uuid::v7()->toRfc4122();
        $old = new \DateTimeImmutable('-10 seconds');
        $this->insertOutbox($first, $transactionId, 'claimed', $old, $old, null, 1);
        $this->insertOutbox($second, $transactionId, 'claimed', $old->modify('+1 second'), $old->modify('+1 second'), null, 1);

        return [$first, $second];
    }

    private function insertTransaction(Connection $connection, string $type, string $idempotencyKey): string
    {
        $id = Uuid::v7()->toRfc4122();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $connection->insert('ledger_transaction', [
            'id' => $id,
            'type' => $type,
            'status' => 'posted',
            'idempotency_key' => $idempotencyKey,
            'request_hash' => hash('sha256', $idempotencyKey),
            'metadata' => '{}',
            'created_at' => $now,
            'posted_at' => $now,
        ]);

        return $id;
    }

    private function insertPosting(Connection $connection, string $transactionId, string $accountId, int $amountMinor, int $sequence): void
    {
        $connection->insert('posting', [
            'id' => Uuid::v7()->toRfc4122(),
            'transaction_id' => $transactionId,
            'account_id' => $accountId,
            'amount_minor' => $amountMinor,
            'currency' => 'USD',
            'sequence' => $sequence,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    private function insertOutbox(
        string $id,
        string $transactionId,
        string $status,
        \DateTimeImmutable $createdAt,
        ?\DateTimeImmutable $claimedAt,
        ?string $lastError,
        int $attemptCount,
    ): void {
        $payload = ['id' => $id];
        $this->connection->insert('outbox_message', [
            'id' => $id,
            'ledger_transaction_id' => $transactionId,
            'provider_event_id' => null,
            'message_type' => 'wallet.test',
            'deduplication_key' => 'concurrency-'.$id,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'payload_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
            'status' => $status,
            'attempt_count' => $attemptCount,
            'available_at' => $createdAt->format('Y-m-d H:i:s'),
            'claimed_at' => $claimedAt?->format('Y-m-d H:i:s'),
            'dispatched_at' => null,
            'last_error' => $lastError,
            'created_at' => $createdAt->format('Y-m-d H:i:s'),
        ]);
    }
}
