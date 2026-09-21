<?php

declare(strict_types=1);

namespace App\Walleting\Tests\Integration;

use App\Walleting\Entity\Wallet;
use App\Walleting\Entity\WalletAccount;
use App\Walleting\Entity\WalletFinancialOperationLink;
use App\Walleting\Entity\WalletLedgerTransaction;
use App\Walleting\Entity\WalletReservation;
use App\Walleting\Enum\WalletAccountCategory;
use App\Walleting\Enum\WalletTransactionType;
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
        $asset = new WalletAccount($wallet, 'asset', 'USD', WalletAccountCategory::Clearing);
        $clearing = new WalletAccount($wallet, 'clearing', 'USD', WalletAccountCategory::Clearing);
        $source = $this->posted(WalletTransactionType::Credit, 'inverse-exclusive-source', $asset, 500, $clearing, -500);
        $refund = $this->posted(WalletTransactionType::Refund, 'inverse-exclusive-refund', $asset, -200, $clearing, 200);
        $reverse = $this->posted(WalletTransactionType::Reverse, 'inverse-exclusive-reverse', $asset, -500, $clearing, 500);
        foreach ([$wallet, $asset, $clearing, $source, $refund, $reverse] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->persist(new WalletFinancialOperationLink(WalletTransactionType::Refund, $source, $refund, 200));
        $this->entityManager->flush();

        $this->entityManager->persist(new WalletFinancialOperationLink(WalletTransactionType::Reverse, $source, $reverse, 500));

        $this->expectException(DriverException::class);
        $this->expectExceptionMessage('reverse requires the full untouched source transaction');
        $this->entityManager->flush();
    }

    public function testReservationSettlementCannotExceedReservedAmount(): void
    {
        $wallet = new Wallet('vendor', 'settlement-exclusive-wallet');
        $reserved = new WalletAccount($wallet, 'reserved', 'USD', WalletAccountCategory::Reserve);
        $destination = new WalletAccount($wallet, 'destination', 'USD', WalletAccountCategory::Clearing);
        $other = new WalletAccount($wallet, 'other', 'USD', WalletAccountCategory::Clearing);
        $reserveTransaction = $this->posted(WalletTransactionType::Reserve, 'settlement-exclusive-reserve', $destination, -500, $reserved, 500);
        foreach ([$wallet, $reserved, $destination, $other, $reserveTransaction] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();

        $capture = $this->posted(WalletTransactionType::Capture, 'settlement-exclusive-capture', $reserved, -300, $destination, 300);
        $release = $this->posted(WalletTransactionType::Release, 'settlement-exclusive-release', $destination, -250, $other, 250);
        $reservation = new WalletReservation($wallet, $reserved, $reserveTransaction, 500, 'USD', 'settlement-exclusive-reservation');
        foreach ([$capture, $release, $reservation] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->persist(new WalletFinancialOperationLink(WalletTransactionType::Capture, $reserveTransaction, $capture, 300, $reservation));
        $this->entityManager->flush();

        $this->entityManager->persist(new WalletFinancialOperationLink(WalletTransactionType::Release, $reserveTransaction, $release, 250, $reservation));

        $this->expectException(DriverException::class);
        $this->expectExceptionMessage('reservation settlement exceeds reserved amount');
        $this->entityManager->flush();
    }

    private function posted(WalletTransactionType $type, string $key, WalletAccount $first, int $firstAmount, WalletAccount $second, int $secondAmount): WalletLedgerTransaction
    {
        $transaction = new WalletLedgerTransaction($type, $key);
        $transaction->addPosting($first, $firstAmount);
        $transaction->addPosting($second, $secondAmount);
        $transaction->post();

        return $transaction;
    }
}
