<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Account;
use App\Enum\TransactionStatus;
use App\Ledger\PostingInstruction;
use App\Service\OutboxService;
use App\Service\PostingDbalExecutor;
use App\Service\PostingRetryPolicy;
use App\Service\PostingService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class PostgreSqlPostingServiceTest extends KernelTestCase
{
    private Connection $connection;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $this->entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(\Doctrine\DBAL\Platforms\PostgreSQLPlatform::class, $this->connection->getDatabasePlatform());
    }

    public function testStandalonePostingUsesAtomicDbalLedgerAndOutboxPath(): void
    {
        [$assetId, $clearingId] = $this->seedAccounts();
        $asset = $this->entityManager->find(Account::class, $assetId);
        $clearing = $this->entityManager->find(Account::class, $clearingId);
        self::assertInstanceOf(Account::class, $asset);
        self::assertInstanceOf(Account::class, $clearing);

        $outboxService = new OutboxService($this->entityManager, $this->connection);
        $service = new PostingService(
            $this->entityManager,
            $outboxService,
            new PostingDbalExecutor($this->connection, $outboxService, new PostingRetryPolicy(), new \App\Service\NullPostingTelemetry()),
        );
        $key = 'dbal-hot-path-'.Uuid::v7();
        $instructions = [
            new PostingInstruction($asset, 1250),
            new PostingInstruction($clearing, -1250),
        ];

        $transaction = $service->credit($key, $instructions, ['operation' => 'integration_credit']);

        self::assertSame(TransactionStatus::Posted, $transaction->status());
        self::assertSame($key, $transaction->idempotencyKey());
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM posting WHERE transaction_id = ?', [$transaction->id()->toRfc4122()]));
        self::assertSame(1250, (int) $this->connection->fetchOne('SELECT balance_minor FROM account_balance WHERE account_id = ?', [$assetId]));
        self::assertSame(-1250, (int) $this->connection->fetchOne('SELECT balance_minor FROM account_balance WHERE account_id = ?', [$clearingId]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM outbox_message WHERE ledger_transaction_id = ? AND message_type = 'ledger.transaction.posted'", [$transaction->id()->toRfc4122()]));

        $replayed = $service->credit($key, $instructions, ['operation' => 'integration_credit']);
        self::assertSame($transaction->id()->toRfc4122(), $replayed->id()->toRfc4122());
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ledger_transaction WHERE idempotency_key = ?', [$key]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM outbox_message WHERE ledger_transaction_id = ? AND message_type = 'ledger.transaction.posted'", [$transaction->id()->toRfc4122()]));
    }

    public function testOppositeDirectionTransfersDoNotDeadlockUnderConcurrentPostingServiceWorkers(): void
    {
        [$accountAId, $accountBId, $clearingId] = $this->seedTransferAccounts();
        $this->seedBalances($accountAId, $accountBId, $clearingId);

        $token = Uuid::v7()->toRfc4122();
        $barrier = sys_get_temp_dir().DIRECTORY_SEPARATOR.'walleting-posting-barrier-'.$token;
        $readyA = $barrier.'.a.ready';
        $readyB = $barrier.'.b.ready';
        foreach ([$barrier, $readyA, $readyB] as $file) {
            @unlink($file);
        }
        $worker = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'posting-concurrency-worker.php';
        $keyA = 'concurrent-a-'.Uuid::v7();
        $keyB = 'concurrent-b-'.Uuid::v7();

        $processA = $this->startWorker([$worker, $accountAId, $accountBId, '100', $keyA, $barrier, $readyA]);
        $processB = $this->startWorker([$worker, $accountBId, $accountAId, '200', $keyB, $barrier, $readyB]);

        try {
            $this->awaitWorkersReady([$readyA, $readyB]);
            touch($barrier);
            $resultA = $this->finishWorker($processA);
            $resultB = $this->finishWorker($processB);
        } finally {
            foreach ([$barrier, $readyA, $readyB] as $file) {
                @unlink($file);
            }
        }

        self::assertTrue($resultA['ok'], $resultA['error'] ?? 'Worker A failed.');
        self::assertTrue($resultB['ok'], $resultB['error'] ?? 'Worker B failed.');
        self::assertSame(1100, (int) $this->connection->fetchOne('SELECT balance_minor FROM account_balance WHERE account_id = ?', [$accountAId]));
        self::assertSame(900, (int) $this->connection->fetchOne('SELECT balance_minor FROM account_balance WHERE account_id = ?', [$accountBId]));
        self::assertSame(-2000, (int) $this->connection->fetchOne('SELECT balance_minor FROM account_balance WHERE account_id = ?', [$clearingId]));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ledger_transaction WHERE idempotency_key IN (?, ?)', [$keyA, $keyB]));
        self::assertSame(2, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM outbox_message WHERE message_type = 'ledger.transaction.posted' AND ledger_transaction_id IN (SELECT id FROM ledger_transaction WHERE idempotency_key IN (?, ?))", [$keyA, $keyB]));
    }

    public function testTransientLockTimeoutIsRetriedAfterCompetingLockReleases(): void
    {
        [$accountAId, $accountBId, $clearingId] = $this->seedTransferAccounts();
        $this->seedBalances($accountAId, $accountBId, $clearingId);

        $locker = \Doctrine\DBAL\DriverManager::getConnection($this->connection->getParams());
        $locker->beginTransaction();
        $locker->fetchOne('SELECT id FROM account WHERE id = ? FOR UPDATE', [$accountAId]);

        $token = Uuid::v7()->toRfc4122();
        $barrier = sys_get_temp_dir().DIRECTORY_SEPARATOR.'walleting-retry-barrier-'.$token;
        $ready = $barrier.'.ready';
        @unlink($barrier);
        @unlink($ready);
        $worker = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'posting-concurrency-worker.php';
        $key = 'retry-lock-'.Uuid::v7();
        foreach ([
            'WALLETING_POSTING_LOCK_TIMEOUT_MS=100',
            'WALLETING_POSTING_MAX_ATTEMPTS=3',
            'WALLETING_POSTING_BASE_DELAY_MS=25',
            'WALLETING_POSTING_MAX_DELAY_MS=50',
        ] as $setting) {
            putenv($setting);
        }
        $process = $this->startWorker([$worker, $accountAId, $accountBId, '100', $key, $barrier, $ready]);

        try {
            $this->awaitWorkersReady([$ready]);
            touch($barrier);
            usleep(150000);
            $locker->commit();
            $result = $this->finishWorker($process);
        } finally {
            if ($locker->isTransactionActive()) {
                $locker->rollBack();
            }
            $locker->close();
            foreach ([
                'WALLETING_POSTING_LOCK_TIMEOUT_MS',
                'WALLETING_POSTING_MAX_ATTEMPTS',
                'WALLETING_POSTING_BASE_DELAY_MS',
                'WALLETING_POSTING_MAX_DELAY_MS',
            ] as $name) {
                putenv($name);
            }
            @unlink($barrier);
            @unlink($ready);
        }

        self::assertTrue($result['ok'], $result['error'] ?? 'Retry worker failed.');
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ledger_transaction WHERE idempotency_key = ?', [$key]));
    }

    /** @param list<string> $readyFiles */
    private function awaitWorkersReady(array $readyFiles): void
    {
        $deadline = microtime(true) + 5.0;
        do {
            $allReady = true;
            foreach ($readyFiles as $readyFile) {
                if (!is_file($readyFile)) {
                    $allReady = false;
                    break;
                }
            }
            if ($allReady) {
                return;
            }
            usleep(1000);
        } while (microtime(true) < $deadline);

        throw new \RuntimeException('PostingService concurrency workers did not become ready in time.');
    }

    /** @return array{resource, array<int, resource>} */
    private function startWorker(array $arguments): array
    {
        $command = array_merge([PHP_BINARY], $arguments);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptors, $pipes, dirname(__DIR__, 2));
        if (!is_resource($process)) {
            throw new \RuntimeException('Could not start PostingService concurrency worker.');
        }
        fclose($pipes[0]);

        return [$process, $pipes];
    }

    /** @param array{resource, array<int, resource>} $worker @return array<string, mixed> */
    private function finishWorker(array $worker): array
    {
        [$process, $pipes] = $worker;
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        $decoded = json_decode(trim((string) $stdout), true);
        if (!is_array($decoded)) {
            throw new \RuntimeException(sprintf('Posting worker returned invalid output (exit %d): %s %s', $exitCode, $stdout, $stderr));
        }
        if (0 !== $exitCode && !isset($decoded['error'])) {
            $decoded['error'] = trim((string) $stderr) ?: 'Posting worker failed.';
        }

        return $decoded;
    }

    /** @return array{string, string, string} */
    private function seedTransferAccounts(): array
    {
        $walletId = Uuid::v7()->toRfc4122();
        $accountAId = Uuid::v7()->toRfc4122();
        $accountBId = Uuid::v7()->toRfc4122();
        $clearingId = Uuid::v7()->toRfc4122();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->connection->insert('wallet', [
            'id' => $walletId,
            'owner_type' => 'posting-service-concurrency',
            'owner_id' => Uuid::v7()->toRfc4122(),
            'status' => 'active',
            'created_at' => $now,
        ]);
        foreach ([
            [$accountAId, 'asset-a', 'asset', false],
            [$accountBId, 'asset-b', 'asset', false],
            [$clearingId, 'clearing', 'clearing', true],
        ] as [$id, $code, $category, $allowNegative]) {
            $this->connection->insert('account', [
                'id' => $id,
                'wallet_id' => $walletId,
                'code' => $code,
                'currency' => 'USD',
                'category' => $category,
                'allow_negative' => $allowNegative,
                'created_at' => $now,
            ], ['allow_negative' => ParameterType::BOOLEAN]);
        }

        return [$accountAId, $accountBId, $clearingId];
    }

    private function seedBalances(string $accountAId, string $accountBId, string $clearingId): void
    {
        $transactionId = Uuid::v7()->toRfc4122();
        $timestamp = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->connection->transactional(function (Connection $connection) use ($transactionId, $timestamp, $accountAId, $accountBId, $clearingId): void {
            $connection->insert('ledger_transaction', [
                'id' => $transactionId,
                'type' => 'credit',
                'status' => 'posted',
                'idempotency_key' => 'seed-concurrent-'.Uuid::v7(),
                'request_hash' => hash('sha256', $transactionId),
                'metadata' => '{}',
                'created_at' => $timestamp,
                'posted_at' => $timestamp,
            ]);
            foreach ([
                [$accountAId, 1000],
                [$accountBId, 1000],
                [$clearingId, -2000],
            ] as $index => [$accountId, $amountMinor]) {
                $connection->insert('posting', [
                    'id' => Uuid::v7()->toRfc4122(),
                    'transaction_id' => $transactionId,
                    'account_id' => $accountId,
                    'amount_minor' => $amountMinor,
                    'currency' => 'USD',
                    'sequence' => $index + 1,
                    'created_at' => $timestamp,
                ]);
            }
        });
    }

    /** @return array{string, string} */
    private function seedAccounts(): array
    {
        $walletId = Uuid::v7()->toRfc4122();
        $assetId = Uuid::v7()->toRfc4122();
        $clearingId = Uuid::v7()->toRfc4122();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->connection->insert('wallet', [
            'id' => $walletId,
            'owner_type' => 'posting-service-integration',
            'owner_id' => Uuid::v7()->toRfc4122(),
            'status' => 'active',
            'created_at' => $now,
        ]);
        $this->connection->insert('account', [
            'id' => $assetId,
            'wallet_id' => $walletId,
            'code' => 'cash',
            'currency' => 'USD',
            'category' => 'asset',
            'allow_negative' => false,
            'created_at' => $now,
        ], ['allow_negative' => ParameterType::BOOLEAN]);
        $this->connection->insert('account', [
            'id' => $clearingId,
            'wallet_id' => $walletId,
            'code' => 'clearing',
            'currency' => 'USD',
            'category' => 'clearing',
            'allow_negative' => true,
            'created_at' => $now,
        ], ['allow_negative' => ParameterType::BOOLEAN]);

        return [$assetId, $clearingId];
    }
}
