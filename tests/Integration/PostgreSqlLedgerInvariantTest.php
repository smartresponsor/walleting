<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\ParameterType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class PostgreSqlLedgerInvariantTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(\Doctrine\DBAL\Platforms\PostgreSQLPlatform::class, $this->connection->getDatabasePlatform());

        $schema = $this->connection->createSchemaManager();
        foreach (['wallet', 'account', 'account_balance', 'ledger_transaction', 'posting', 'reservation', 'financial_operation_link'] as $table) {
            self::assertTrue($schema->tablesExist([$table]), sprintf('Migrated table "%s" is missing.', $table));
        }
    }

    public function testDeferredBalanceConstraintRejectsUnbalancedPostedTransaction(): void
    {
        [, $accountA, $accountB] = $this->seedWalletAndAccounts();
        $transactionId = Uuid::v7()->toRfc4122();

        $this->connection->beginTransaction();
        try {
            $this->insertTransaction($transactionId, 'credit', 'unbalanced-'.Uuid::v7(), 'posted');
            $this->insertPosting($transactionId, $accountA, 1000, 1);
            $this->insertPosting($transactionId, $accountB, -900, 2);

            $this->expectException(Exception::class);
            $this->connection->commit();
        } finally {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
        }
    }

    public function testPostingAndPostedTransactionAreImmutable(): void
    {
        [, $accountA, $accountB] = $this->seedWalletAndAccounts();
        $transactionId = Uuid::v7()->toRfc4122();
        $postingId = Uuid::v7()->toRfc4122();

        $this->connection->beginTransaction();
        $this->insertTransaction($transactionId, 'credit', 'immutable-'.Uuid::v7(), 'posted');
        $this->insertPosting($transactionId, $accountA, 1000, 1, $postingId);
        $this->insertPosting($transactionId, $accountB, -1000, 2);
        $this->connection->commit();

        try {
            $this->connection->executeStatement('UPDATE posting SET amount_minor = 900 WHERE id = ?', [$postingId]);
            self::fail('Posting mutation must be rejected.');
        } catch (Exception $exception) {
            self::assertStringContainsString('append-only', $exception->getMessage());
        }

        try {
            $this->connection->executeStatement('UPDATE ledger_transaction SET metadata = ? WHERE id = ?', ['{}', $transactionId]);
            self::fail('Posted transaction mutation must be rejected.');
        } catch (Exception $exception) {
            self::assertStringContainsString('immutable', $exception->getMessage());
        }
    }

    public function testDuplicateRefundAndRollbackAreDatabaseEnforced(): void
    {
        [, $accountA, $accountB] = $this->seedWalletAndAccounts();
        $sourceId = $this->seedBalancedTransaction('credit', $accountA, $accountB);
        $resultA = $this->seedBalancedTransaction('refund', $accountA, $accountB);
        $resultB = $this->seedBalancedTransaction('refund', $accountA, $accountB);

        $this->insertOperationLink($sourceId, $resultA);
        try {
            $this->insertOperationLink($sourceId, $resultB);
            self::fail('Duplicate refund link must be rejected.');
        } catch (Exception) {
            self::assertTrue(true);
        }

        $rolledBackId = Uuid::v7()->toRfc4122();
        $this->connection->beginTransaction();
        $this->insertTransaction($rolledBackId, 'credit', 'rollback-'.Uuid::v7(), 'pending');
        $this->insertPosting($rolledBackId, $accountA, 1000, 1);
        $this->connection->rollBack();

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ledger_transaction WHERE id = ?', [$rolledBackId]));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM posting WHERE transaction_id = ?', [$rolledBackId]));
    }

    public function testIndependentConnectionsCannotReuseIdempotencyKey(): void
    {
        [, $accountA, $accountB] = $this->seedWalletAndAccounts();
        $idempotencyKey = 'independent-'.Uuid::v7();
        $firstId = $this->seedBalancedTransaction('credit', $accountA, $accountB, $idempotencyKey);
        $secondConnection = DriverManager::getConnection($this->connection->getParams());

        try {
            $secondConnection->beginTransaction();
            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            $secondConnection->insert('ledger_transaction', [
                'id' => Uuid::v7()->toRfc4122(),
                'type' => 'credit',
                'status' => 'pending',
                'idempotency_key' => $idempotencyKey,
                'request_hash' => hash('sha256', $idempotencyKey.'-different'),
                'metadata' => '{}',
                'created_at' => $now,
                'posted_at' => null,
            ]);
            self::fail('A second connection must not reuse an existing idempotency key.');
        } catch (Exception) {
            self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ledger_transaction WHERE id = ?', [$firstId]));
        } finally {
            if ($secondConnection->isTransactionActive()) {
                $secondConnection->rollBack();
            }
            $secondConnection->close();
        }
    }

    public function testSpendableAccountCannotOverdraw(): void
    {
        [, $accountA, $accountB] = $this->seedWalletAndAccounts();
        $transactionId = Uuid::v7()->toRfc4122();

        $this->connection->beginTransaction();
        try {
            $this->insertTransaction($transactionId, 'debit', 'overdraft-'.Uuid::v7(), 'posted');
            $this->insertPosting($transactionId, $accountA, -1, 1);
            $this->insertPosting($transactionId, $accountB, 1, 2);
            $this->connection->commit();
            self::fail('A non-negative account must reject overdraft.');
        } catch (Exception $exception) {
            self::assertStringContainsString('insufficient available balance', $exception->getMessage());
        } finally {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
        }
    }

    public function testAccountBalanceProjectionIsAtomicAndDerived(): void
    {
        [, $accountA, $accountB] = $this->seedWalletAndAccounts();
        $this->seedBalancedTransaction('credit', $accountA, $accountB);

        self::assertSame(1000, (int) $this->connection->fetchOne('SELECT balance_minor FROM account_balance WHERE account_id = ?', [$accountA]));
        self::assertSame(-1000, (int) $this->connection->fetchOne('SELECT balance_minor FROM account_balance WHERE account_id = ?', [$accountB]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT posting_count FROM account_balance WHERE account_id = ?', [$accountA]));

        try {
            $this->connection->executeStatement('UPDATE account_balance SET balance_minor = 0 WHERE account_id = ?', [$accountA]);
            self::fail('Direct account balance mutation must be rejected.');
        } catch (Exception $exception) {
            self::assertStringContainsString('derived from postings', $exception->getMessage());
        }
    }

    private function seedWalletAndAccounts(): array
    {
        $walletId = Uuid::v7()->toRfc4122();
        $accountA = Uuid::v7()->toRfc4122();
        $accountB = Uuid::v7()->toRfc4122();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->connection->insert('wallet', ['id' => $walletId, 'owner_type' => 'integration', 'owner_id' => Uuid::v7()->toRfc4122(), 'status' => 'active', 'object_uuid' => '\\x'.str_replace('-', '', $walletId), 'object_slug' => 'wallet:'.$walletId, 'object_first_title' => 'integration', 'object_created_at' => $now, 'object_status' => 'active'], []);
        foreach ([[$accountA, 'asset', false], [$accountB, 'clearing', true]] as [$id, $category, $allowNegative]) {
            $this->connection->insert('account', ['id' => $id, 'wallet_id' => $walletId, 'code' => $category.'-'.substr($id, 0, 8), 'currency' => 'USD', 'category' => $category, 'allow_negative' => $allowNegative, 'object_uuid' => '\\x'.str_replace('-', '', $id), 'object_slug' => 'account:'.$id, 'object_first_title' => $category.'-'.substr($id, 0, 8), 'object_created_at' => $now, 'object_status' => 'active'], ['allow_negative' => ParameterType::BOOLEAN]);
        }

        return [$walletId, $accountA, $accountB];
    }

    private function seedBalancedTransaction(string $type, string $accountA, string $accountB, ?string $idempotencyKey = null): string
    {
        $id = Uuid::v7()->toRfc4122();
        $this->connection->beginTransaction();
        $this->insertTransaction($id, $type, $idempotencyKey ?? $type.'-'.Uuid::v7(), 'posted');
        $this->insertPosting($id, $accountA, 1000, 1);
        $this->insertPosting($id, $accountB, -1000, 2);
        $this->connection->commit();

        return $id;
    }

    private function insertTransaction(string $id, string $type, string $idempotencyKey, string $status): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->connection->insert('ledger_transaction', ['id' => $id, 'type' => $type, 'status' => $status, 'idempotency_key' => $idempotencyKey, 'request_hash' => hash('sha256', $idempotencyKey), 'metadata' => '{}', 'created_at' => $now, 'posted_at' => 'posted' === $status ? $now : null]);
    }

    private function insertPosting(string $transactionId, string $accountId, int $amountMinor, int $sequence, ?string $id = null): void
    {
        $this->connection->insert('posting', ['id' => $id ?? Uuid::v7()->toRfc4122(), 'transaction_id' => $transactionId, 'account_id' => $accountId, 'amount_minor' => $amountMinor, 'currency' => 'USD', 'sequence' => $sequence, 'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')]);
    }

    private function insertOperationLink(string $sourceId, string $resultId): void
    {
        $this->connection->insert('financial_operation_link', ['id' => Uuid::v7()->toRfc4122(), 'operation_type' => 'refund', 'source_transaction_id' => $sourceId, 'result_transaction_id' => $resultId, 'reservation_id' => null, 'object_created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')]);
    }
}
