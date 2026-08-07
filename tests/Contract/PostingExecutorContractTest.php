<?php

declare(strict_types=1);

namespace App\Tests\Contract;

use App\Entity\Account;
use App\Enum\TransactionType;
use App\Ledger\FinancialPostingRequest;
use App\Ledger\PostingInstruction;
use App\Service\PostingExecutorInterface;
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
        $this->connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $this->entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(\Doctrine\DBAL\Platforms\PostgreSQLPlatform::class, $this->connection->getDatabasePlatform());
    }

    abstract protected function createExecutor(): PostingExecutorInterface;

    public function testSuccessfulExecutionCommitsLedgerPostingsAndOutboxAtomically(): void
    {
        [$asset, $clearing] = $this->accounts();
        $request = new FinancialPostingRequest(TransactionType::Credit, [
            new PostingInstruction($asset, 1250),
            new PostingInstruction($clearing, -1250),
        ], ['operation' => 'executor_contract']);
        $key = 'executor-success-'.Uuid::v7();

        $transactionId = $this->createExecutor()->execute($key, $request);

        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ledger_transaction WHERE id = ? AND idempotency_key = ? AND request_hash = ?', [$transactionId, $key, $request->hash()]));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM posting WHERE transaction_id = ?', [$transactionId]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM outbox_message WHERE ledger_transaction_id = ? AND message_type = 'ledger.transaction.posted'", [$transactionId]));
    }

    public function testPostingSequenceMatchesCanonicalRequestOrder(): void
    {
        [$asset, $clearing, $clearingAlt] = $this->accounts(includeThirdAccount: true);
        self::assertInstanceOf(Account::class, $clearingAlt);
        $request = new FinancialPostingRequest(TransactionType::Credit, [
            new PostingInstruction($asset, 500),
            new PostingInstruction($clearing, -200),
            new PostingInstruction($clearingAlt, -300),
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
        $request = new FinancialPostingRequest(TransactionType::Credit, [
            new PostingInstruction($asset, 500),
            new PostingInstruction($clearing, -500),
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
            self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM outbox_message WHERE ledger_transaction_id = ?', [$transactionId]));
        }
    }

    public function testOverdraftFailureRollsBackTransactionPostingsAndOutbox(): void
    {
        [$asset, $clearing] = $this->accounts();
        $request = new FinancialPostingRequest(TransactionType::Debit, [
            new PostingInstruction($asset, -100),
            new PostingInstruction($clearing, 100),
        ]);
        $key = 'executor-overdraft-'.Uuid::v7();

        try {
            $this->createExecutor()->execute($key, $request);
            self::fail('Executor must reject an overdraft on a non-negative asset account.');
        } catch (\Throwable $exception) {
            self::assertStringContainsString('insufficient available balance', strtolower($exception->getMessage()));
            self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ledger_transaction WHERE idempotency_key = ?', [$key]));
            self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM outbox_message WHERE payload ->> 'idempotency_key' = ?", [$key]));
        }
    }

    /** @return array{Account, Account, ?Account} */
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
                'created_at' => $now,
            ], ['allow_negative' => ParameterType::BOOLEAN]);
        }

        $asset = $this->entityManager->find(Account::class, $assetId);
        $clearing = $this->entityManager->find(Account::class, $clearingId);
        $thirdAccount = null === $thirdAccountId ? null : $this->entityManager->find(Account::class, $thirdAccountId);
        self::assertInstanceOf(Account::class, $asset);
        self::assertInstanceOf(Account::class, $clearing);
        if (null !== $thirdAccountId) {
            self::assertInstanceOf(Account::class, $thirdAccount);
        }

        return [$asset, $clearing, $thirdAccount];
    }
}
