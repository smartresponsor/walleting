<?php

declare(strict_types=1);

namespace App\Walleting\Tests\Integration;

use App\Walleting\Entity\Account;
use App\Walleting\Entity\FinancialOperationLink;
use App\Walleting\Entity\LedgerTransaction;
use App\Walleting\Entity\Reservation;
use App\Walleting\Entity\Wallet;
use App\Walleting\Enum\AccountCategory;
use App\Walleting\Enum\TransactionType;
use Doctrine\DBAL\Exception\DriverException;
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

    public function testReverseIsRejectedAfterPartialRefund(): void
    {
        $wallet = new Wallet('vendor', 'inverse-exclusive-wallet');
        $asset = new Account($wallet, 'asset', 'USD', AccountCategory::Clearing);
        $clearing = new Account($wallet, 'clearing', 'USD', AccountCategory::Clearing);
        $source = $this->posted(TransactionType::Credit, 'inverse-exclusive-source', $asset, 500, $clearing, -500);
        $refund = $this->posted(TransactionType::Refund, 'inverse-exclusive-refund', $asset, -200, $clearing, 200);
        $reverse = $this->posted(TransactionType::Reverse, 'inverse-exclusive-reverse', $asset, -500, $clearing, 500);
        foreach ([$wallet, $asset, $clearing, $source, $refund, $reverse] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->persist(new FinancialOperationLink(TransactionType::Refund, $source, $refund, 200));
        $this->entityManager->flush();

        $this->entityManager->persist(new FinancialOperationLink(TransactionType::Reverse, $source, $reverse, 500));

        $this->expectException(DriverException::class);
        $this->expectExceptionMessage('reverse requires the full untouched source transaction');
        $this->entityManager->flush();
    }

    public function testReservationSettlementCannotExceedReservedAmount(): void
    {
        $wallet = new Wallet('vendor', 'settlement-exclusive-wallet');
        $reserved = new Account($wallet, 'reserved', 'USD', AccountCategory::Reserve);
        $destination = new Account($wallet, 'destination', 'USD', AccountCategory::Clearing);
        $other = new Account($wallet, 'other', 'USD', AccountCategory::Clearing);
        $reserveTransaction = $this->posted(TransactionType::Reserve, 'settlement-exclusive-reserve', $destination, -500, $reserved, 500);
        foreach ([$wallet, $reserved, $destination, $other, $reserveTransaction] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();

        $capture = $this->posted(TransactionType::Capture, 'settlement-exclusive-capture', $reserved, -300, $destination, 300);
        $release = $this->posted(TransactionType::Release, 'settlement-exclusive-release', $destination, -250, $other, 250);
        $reservation = new Reservation($wallet, $reserved, $reserveTransaction, 500, 'USD', 'settlement-exclusive-reservation');
        foreach ([$capture, $release, $reservation] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->persist(new FinancialOperationLink(TransactionType::Capture, $reserveTransaction, $capture, 300, $reservation));
        $this->entityManager->flush();

        $this->entityManager->persist(new FinancialOperationLink(TransactionType::Release, $reserveTransaction, $release, 250, $reservation));

        $this->expectException(DriverException::class);
        $this->expectExceptionMessage('reservation settlement exceeds reserved amount');
        $this->entityManager->flush();
    }

    private function posted(TransactionType $type, string $key, Account $first, int $firstAmount, Account $second, int $secondAmount): LedgerTransaction
    {
        $transaction = new LedgerTransaction($type, $key);
        $transaction->addPosting($first, $firstAmount);
        $transaction->addPosting($second, $secondAmount);
        $transaction->post();

        return $transaction;
    }
}
