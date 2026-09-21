<?php

declare(strict_types=1);

namespace App\Walleting\Tests\Integration;

use App\Walleting\Entity\Wallet;
use App\Walleting\Entity\WalletFunding;
use App\Walleting\Entity\WalletPaymentInstrument;
use App\Walleting\Entity\WalletReconciliationMismatch;
use App\Walleting\Entity\WalletReconciliationRun;
use App\Walleting\Entity\WalletWithdrawal;
use App\Walleting\Enum\WalletPaymentInstrumentType;
use App\Walleting\Enum\WalletReconciliationMismatchType;
use App\Walleting\Enum\WalletReconciliationRunStatus;
use App\Walleting\Service\WalletProviderSettlementReconciliationService;
use App\Walleting\Service\WalletProviderSettlementRecord;
use App\Walleting\Service\WalletReconciliationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PostgreSqlProviderSettlementReconciliationTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
    }

    public function testProviderSettlementBatchMatchesWalletingOperationsAndPersistsMismatches(): void
    {
        $wallet = new Wallet('vendor', 'provider-settlement-wallet');
        $instrument = new WalletPaymentInstrument($wallet, WalletPaymentInstrumentType::Card, 'stripe', 'pm_provider_settlement', 'Card');
        $funding = new WalletFunding($wallet, $instrument, 1000, 'USD', 'provider-settlement-funding');
        $funding->start();
        $funding->bindProviderOperationReference('ch_provider_settlement');
        $withdrawal = new WalletWithdrawal($wallet, $instrument, 400, 'USD', 'provider-settlement-withdrawal');
        $withdrawal->start();
        $withdrawal->bindProviderOperationReference('po_provider_settlement');
        $run = new WalletReconciliationRun('stripe', 'provider-settlement-run');
        foreach ([$wallet, $instrument, $funding, $withdrawal, $run] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();

        $service = new WalletProviderSettlementReconciliationService(new WalletReconciliationService($this->entityManager));
        $service->execute($run, [
            new WalletProviderSettlementRecord('funding', 'ch_provider_settlement', 1000, 'USD', 'processing'),
            new WalletProviderSettlementRecord('funding', 'ch_missing_provider_settlement', 250, 'USD', 'processing'),
        ], [$funding, $withdrawal]);

        self::assertSame(WalletReconciliationRunStatus::Completed, $run->status());
        self::assertSame(3, $run->checkedCount());
        self::assertSame(1, $run->matchedCount());
        self::assertSame(2, $run->mismatchCount());

        $mismatches = $this->entityManager->getRepository(WalletReconciliationMismatch::class)->findBy(['run' => $run]);
        self::assertCount(2, $mismatches);
        self::assertSame(
            [WalletReconciliationMismatchType::MissingLocal, WalletReconciliationMismatchType::MissingProvider],
            array_values(array_map(static fn (WalletReconciliationMismatch $mismatch): WalletReconciliationMismatchType => $mismatch->type(), $mismatches)),
        );
    }
}
