<?php

declare(strict_types=1);

namespace App\Walleting\Tests\Integration;

use App\Walleting\Entity\Account;
use App\Walleting\Entity\LedgerTransaction;
use App\Walleting\Entity\Reservation;
use App\Walleting\Entity\Wallet;
use App\Walleting\Enum\AccountCategory;
use App\Walleting\Enum\TransactionType;
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
        $source = $this->persistTransaction(TransactionType::Refund, 'link-semantic-source-refund');
        $result = $this->persistTransaction(TransactionType::Reverse, 'link-semantic-result-reverse');

        $this->expectSemanticViolation('refund and reverse cannot originate from an inverse transaction');
        $this->insertLink(TransactionType::Reverse, $source, $result);
    }

    public function testDatabaseRejectsResultTypeThatDoesNotMatchOperation(): void
    {
        $source = $this->persistTransaction(TransactionType::Credit, 'link-semantic-source-credit');
        $result = $this->persistTransaction(TransactionType::Reverse, 'link-semantic-result-mismatch');

        $this->expectSemanticViolation('financial operation result type must match operation type');
        $this->insertLink(TransactionType::Refund, $source, $result);
    }

    public function testDatabaseRejectsCaptureThatDoesNotOriginateFromReserve(): void
    {
        $wallet = new Wallet('vendor', 'link-semantic-wallet');
        $account = new Account($wallet, 'reserved', 'USD', AccountCategory::Reserve);
        $source = new LedgerTransaction(TransactionType::Credit, 'link-semantic-capture-source');
        $result = new LedgerTransaction(TransactionType::Capture, 'link-semantic-capture-result');
        $reservation = new Reservation($wallet, $account, $source, 500, 'USD', 'link-semantic-reservation');
        foreach ([$wallet, $account, $source, $result, $reservation] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();

        $this->expectSemanticViolation('capture and release must originate from a reserve transaction');
        $this->insertLink(TransactionType::Capture, $source, $result, $reservation);
    }

    private function persistTransaction(TransactionType $type, string $idempotencyKey): LedgerTransaction
    {
        $transaction = new LedgerTransaction($type, $idempotencyKey);
        $this->entityManager->persist($transaction);
        $this->entityManager->flush();

        return $transaction;
    }

    private function insertLink(TransactionType $operationType, LedgerTransaction $source, LedgerTransaction $result, ?Reservation $reservation = null): void
    {
        $this->connection->insert('financial_operation_link', [
            'id' => Uuid::v7()->toRfc4122(),
            'operation_type' => $operationType->value,
            'source_transaction_id' => $source->id()->toRfc4122(),
            'result_transaction_id' => $result->id()->toRfc4122(),
            'reservation_id' => $reservation?->id()->toRfc4122(),
            'amount_minor' => 1,
            'object_created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    private function expectSemanticViolation(string $message): void
    {
        $this->expectException(DriverException::class);
        $this->expectExceptionMessage($message);
    }
}
