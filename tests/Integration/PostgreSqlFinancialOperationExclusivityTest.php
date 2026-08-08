<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Account;
use App\Entity\FinancialOperationLink;
use App\Entity\LedgerTransaction;
use App\Entity\Reservation;
use App\Entity\Wallet;
use App\Enum\AccountCategory;
use App\Enum\TransactionType;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PostgreSqlFinancialOperationExclusivityTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(\Doctrine\DBAL\Platforms\PostgreSQLPlatform::class, $this->entityManager->getConnection()->getDatabasePlatform());
    }

    public function testRefundAndReverseAreMutuallyExclusiveForOneSourceTransaction(): void
    {
        $source = new LedgerTransaction(TransactionType::Credit, 'inverse-exclusive-source');
        $refund = new LedgerTransaction(TransactionType::Refund, 'inverse-exclusive-refund');
        $reverse = new LedgerTransaction(TransactionType::Reverse, 'inverse-exclusive-reverse');
        $this->entityManager->persist($source);
        $this->entityManager->persist($refund);
        $this->entityManager->persist($reverse);
        $this->entityManager->persist(new FinancialOperationLink(TransactionType::Refund, $source, $refund));
        $this->entityManager->flush();

        $this->entityManager->persist(new FinancialOperationLink(TransactionType::Reverse, $source, $reverse));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->entityManager->flush();
    }

    public function testCaptureAndReleaseAreMutuallyExclusiveForOneReservation(): void
    {
        $wallet = new Wallet('vendor', 'settlement-exclusive-wallet');
        $reserved = new Account($wallet, 'reserved', 'USD', AccountCategory::Reserve);
        $reserveTransaction = new LedgerTransaction(TransactionType::Reserve, 'settlement-exclusive-reserve');
        $capture = new LedgerTransaction(TransactionType::Capture, 'settlement-exclusive-capture');
        $release = new LedgerTransaction(TransactionType::Release, 'settlement-exclusive-release');
        $reservation = new Reservation($wallet, $reserved, $reserveTransaction, 500, 'USD', 'settlement-exclusive-reservation');
        foreach ([$wallet, $reserved, $reserveTransaction, $capture, $release, $reservation] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->persist(new FinancialOperationLink(TransactionType::Capture, $reserveTransaction, $capture, $reservation));
        $this->entityManager->flush();

        $this->entityManager->persist(new FinancialOperationLink(TransactionType::Release, $reserveTransaction, $release, $reservation));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->entityManager->flush();
    }
}
