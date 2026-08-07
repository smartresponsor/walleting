<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Account;
use App\Enum\TransactionStatus;
use App\Ledger\PostingInstruction;
use App\Service\OutboxService;
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

        $service = new PostingService(
            $this->entityManager,
            new OutboxService($this->entityManager, $this->connection),
            $this->connection,
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
