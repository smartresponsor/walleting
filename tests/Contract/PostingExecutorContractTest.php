<?php

declare(strict_types=1);

namespace App\Walleting\Tests\Contract;

use App\Walleting\Entity\WalletAccount;
use App\Walleting\Enum\WalletTransactionType;
use App\Walleting\Service\WalletPostingExecutorInterface;
use App\Walleting\ValueObject\Ledger\WalletFinancialPostingRequest;
use App\Walleting\ValueObject\Ledger\WalletPostingInstruction;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

abstract class PostingExecutorContractTest extends KernelTestCase
{
    protected Connection $connection;
    protected EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(Connection::class, $connection);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->connection = $connection;
        $this->entityManager = $entityManager;
        self::assertInstanceOf(\Doctrine\DBAL\Platforms\PostgreSQLPlatform::class, $this->connection->getDatabasePlatform());
    }

    abstract protected function createExecutor(): WalletPostingExecutorInterface;

    public function testSuccessfulExecutionCommitsLedgerPostingsAndOutboxAtomically(): void
    {
        [$asset, $clearing] = $this->accounts();
        $request = new WalletFinancialPostingRequest(WalletTransactionType::Credit, [
            new WalletPostingInstruction($asset, 1250),
            new WalletPostingInstruction($clearing, -1250),
        ], ['operation' => 'executor_contract']);
        $key = 'executor-success-'.Uuid::v7();

        $transactionId = $this->createExecutor()->execute($key, $request);

        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ledger_transaction WHERE id = ? AND idempotency_key = ? AND request_hash = ?', [$transactionId, $key, $request->hash()]));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM posting WHERE transaction_id = ?', [$transactionId]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM wallet_outbox_message WHERE ledger_transaction_id = ? AND message_type = 'ledger.transaction.posted'", [$transactionId]));
    }

    public function testPostingSequenceMatchesCanonicalRequestOrder(): void
    {
        [$asset, $clearing, $clearingAlt] = $this->accounts(includeThirdAccount: true);
        self::assertInstanceOf(WalletAccount::class, $clearingAlt);
        $request = new WalletFinancialPostingRequest(WalletTransactionType::Credit, [
            new WalletPostingInstruction($asset, 500),
            new WalletPostingInstruction($clearing, -200),
            new WalletPostingInstruction($clearingAlt, -300),
        ]);

        $transactionId = $this->createExecutor()->execute('executor-sequence-'.Uuid::v7(), $request);
        $rows = $this->connection->fetchAllAssociative('SELECT account_id, amount_minor, sequence FROM posting WHERE transaction_id = ? ORDER BY sequence', [$transactionId]);

        self::assertSame([
            [$asset->id()->toRfc4122(), 500, 1],
            [$clearing->id()->toRfc4122(), -200, 2],
            [$clearingAlt->id()->toRfc4122(), -300, 3],
        ], array_map(static fn (array $row): array => [(string) $row['account_id'], (int) $row['amount_minor'], (int) $row['sequence']], $rows));
    }

    public function testDuplicateIdempotencyKeySurfacesCollisionWithoutPartialSecondCommit(): void
    {
        [$asset, $clearing] = $this->accounts();
        $request = new WalletFinancialPostingRequest(WalletTransactionType::Credit, [
            new WalletPostingInstruction($asset, 500),
            new WalletPostingInstruction($clearing, -500),
        ]);
        $key = 'executor-collision-'.Uuid::v7();
        $executor = $this->createExecutor();
        $transactionId = $executor->execute($key, $request);

        try {
            $executor->execute($key, $request);
            self::fail('Executor must surface a duplicate idempotency collision to PostingService.');
        } catch (UniqueConstraintViolationException) {
            self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ledger_transaction WHERE idempotency_key = ?', [$key]));
            self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM posting WHERE transaction_id = ?', [$transactionId]));
            self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM wallet_outbox_message WHERE ledger_transaction_id = ?', [$transactionId]));
        }
    }

    public function testOverdraftFailureRollsBackTransactionPostingsAndOutbox(): void
    {
        [$asset, $clearing] = $this->accounts();
        $request = new WalletFinancialPostingRequest(WalletTransactionType::Debit, [
            new WalletPostingInstruction($asset, -100),
            new WalletPostingInstruction($clearing, 100),
        ]);
        $key = 'executor-overdraft-'.Uuid::v7();

        try {
            $this->createExecutor()->execute($key, $request);
            self::fail('Executor must reject an overdraft on a non-negative asset account.');
        } catch (\Throwable $exception) {
            self::assertStringContainsString('insufficient available balance', strtolower($exception->getMessage()));
            self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ledger_transaction WHERE idempotency_key = ?', [$key]));
            self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM wallet_outbox_message WHERE payload ->> 'idempotency_key' = ?", [$key]));
        }
    }

    /** @return array{WalletAccount, WalletAccount, ?WalletAccount} */
    private function accounts(bool $includeThirdAccount = false): array
    {
        $walletId = Uuid::v7()->toRfc4122();
        $assetId = Uuid::v7()->toRfc4122();
        $clearingId = Uuid::v7()->toRfc4122();
        $thirdAccountId = $includeThirdAccount ? Uuid::v7()->toRfc4122() : null;
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->connection->insert('wallet', [
            'id' => $walletId,
            'owner_type' => 'posting-executor-contract',
            'owner_id' => Uuid::v7()->toRfc4122(),
            'status' => 'active',
            'uuid' => '\\x'.str_replace('-', '', $walletId),
            'slug' => 'wallet:'.$walletId,
            'first_title' => 'posting-executor-contract',
            'created_at' => $now,
        ]);
        foreach ([
            [$assetId, 'asset', 'asset', false],
            [$clearingId, 'clearing', 'clearing', true],
            ...($includeThirdAccount ? [[$thirdAccountId, 'clearing-alt', 'clearing', true]] : []),
        ] as [$id, $code, $category, $allowNegative]) {
            $this->connection->insert('account', [
                'id' => $id,
                'wallet_id' => $walletId,
                'code' => $code.'-'.substr((string) $id, 0, 8),
                'currency' => 'USD',
                'category' => $category,
                'allow_negative' => $allowNegative,
                'uuid' => '\\x'.str_replace('-', '', (string) $id),
                'slug' => 'account:'.$id,
                'first_title' => $code.'-'.substr((string) $id, 0, 8),
                'created_at' => $now,
                'status' => 'active',
            ], ['allow_negative' => ParameterType::BOOLEAN]);
        }

        $asset = $this->entityManager->find(WalletAccount::class, $assetId);
        $clearing = $this->entityManager->find(WalletAccount::class, $clearingId);
        $thirdAccount = null === $thirdAccountId ? null : $this->entityManager->find(WalletAccount::class, $thirdAccountId);
        self::assertInstanceOf(WalletAccount::class, $asset);
        self::assertInstanceOf(WalletAccount::class, $clearing);
        if (null !== $thirdAccountId) {
            self::assertInstanceOf(WalletAccount::class, $thirdAccount);
        }

        return [$asset, $clearing, $thirdAccount];
    }
}
