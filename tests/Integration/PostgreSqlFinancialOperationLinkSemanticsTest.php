<?php

declare(strict_types=1);

namespace App\Walleting\Tests\Integration;

use App\Walleting\Entity\Wallet;
use App\Walleting\Entity\WalletAccount;
use App\Walleting\Entity\WalletLedgerTransaction;
use App\Walleting\Entity\WalletReservation;
use App\Walleting\Enum\WalletAccountCategory;
use App\Walleting\Enum\WalletTransactionType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class PostgreSqlFinancialOperationLinkSemanticsTest extends KernelTestCase
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

    public function testDatabaseRejectsInverseOfInverseLink(): void
    {
        $source = $this->persistTransaction(WalletTransactionType::Refund, 'link-semantic-source-refund');
        $result = $this->persistTransaction(WalletTransactionType::Reverse, 'link-semantic-result-reverse');

        $this->expectSemanticViolation('refund and reverse cannot originate from an inverse transaction');
        $this->insertLink(WalletTransactionType::Reverse, $source, $result);
    }

    public function testDatabaseRejectsResultTypeThatDoesNotMatchOperation(): void
    {
        $source = $this->persistTransaction(WalletTransactionType::Credit, 'link-semantic-source-credit');
        $result = $this->persistTransaction(WalletTransactionType::Reverse, 'link-semantic-result-mismatch');

        $this->expectSemanticViolation('financial operation result type must match operation type');
        $this->insertLink(WalletTransactionType::Refund, $source, $result);
    }

    public function testDatabaseRejectsCaptureThatDoesNotOriginateFromReserve(): void
    {
        $wallet = new Wallet('vendor', 'link-semantic-wallet');
        $account = new WalletAccount($wallet, 'reserved', 'USD', WalletAccountCategory::Reserve);
        $source = new WalletLedgerTransaction(WalletTransactionType::Credit, 'link-semantic-capture-source');
        $result = new WalletLedgerTransaction(WalletTransactionType::Capture, 'link-semantic-capture-result');
        $reservation = new WalletReservation($wallet, $account, $source, 500, 'USD', 'link-semantic-reservation');
        foreach ([$wallet, $account, $source, $result, $reservation] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();

        $this->expectSemanticViolation('capture and release must originate from a reserve transaction');
        $this->insertLink(WalletTransactionType::Capture, $source, $result, $reservation);
    }

    private function persistTransaction(WalletTransactionType $type, string $idempotencyKey): WalletLedgerTransaction
    {
        $transaction = new WalletLedgerTransaction($type, $idempotencyKey);
        $this->entityManager->persist($transaction);
        $this->entityManager->flush();

        return $transaction;
    }

    private function insertLink(WalletTransactionType $operationType, WalletLedgerTransaction $source, WalletLedgerTransaction $result, ?WalletReservation $reservation = null): void
    {
        $this->connection->insert('financial_operation_link', [
            'id' => Uuid::v7()->toRfc4122(),
            'operation_type' => $operationType->value,
            'source_transaction_id' => $source->id()->toRfc4122(),
            'result_transaction_id' => $result->id()->toRfc4122(),
            'reservation_id' => $reservation?->id()->toRfc4122(),
            'amount_minor' => 1,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    private function expectSemanticViolation(string $message): void
    {
        $this->expectException(DriverException::class);
        $this->expectExceptionMessage($message);
    }
}
