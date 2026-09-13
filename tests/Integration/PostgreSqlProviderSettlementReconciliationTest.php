<?php

declare(strict_types=1);

namespace App\Walleting\Tests\Integration;

use App\Walleting\Entity\Funding;
use App\Walleting\Entity\PaymentInstrument;
use App\Walleting\Entity\ReconciliationMismatch;
use App\Walleting\Entity\ReconciliationRun;
use App\Walleting\Entity\Wallet;
use App\Walleting\Entity\Withdrawal;
use App\Walleting\Enum\PaymentInstrumentType;
use App\Walleting\Enum\ReconciliationMismatchType;
use App\Walleting\Enum\ReconciliationRunStatus;
use App\Walleting\Service\ProviderSettlementReconciliationService;
use App\Walleting\Service\ProviderSettlementRecord;
use App\Walleting\Service\ReconciliationService;
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
        $instrument = new PaymentInstrument($wallet, PaymentInstrumentType::Card, 'stripe', 'pm_provider_settlement', 'Card');
        $funding = new Funding($wallet, $instrument, 1000, 'USD', 'provider-settlement-funding');
        $funding->start();
        $funding->bindProviderOperationReference('ch_provider_settlement');
        $withdrawal = new Withdrawal($wallet, $instrument, 400, 'USD', 'provider-settlement-withdrawal');
        $withdrawal->start();
        $withdrawal->bindProviderOperationReference('po_provider_settlement');
        $run = new ReconciliationRun('stripe', 'provider-settlement-run');
        foreach ([$wallet, $instrument, $funding, $withdrawal, $run] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();

        $service = new ProviderSettlementReconciliationService(new ReconciliationService($this->entityManager));
        $service->execute($run, [
            new ProviderSettlementRecord('funding', 'ch_provider_settlement', 1000, 'USD', 'processing'),
            new ProviderSettlementRecord('funding', 'ch_missing_provider_settlement', 250, 'USD', 'processing'),
        ], [$funding, $withdrawal]);

        self::assertSame(ReconciliationRunStatus::Completed, $run->status());
        self::assertSame(3, $run->checkedCount());
        self::assertSame(1, $run->matchedCount());
        self::assertSame(2, $run->mismatchCount());

        $mismatches = $this->entityManager->getRepository(ReconciliationMismatch::class)->findBy(['run' => $run]);
        self::assertCount(2, $mismatches);
        self::assertSame(
            [ReconciliationMismatchType::MissingLocal, ReconciliationMismatchType::MissingProvider],
            array_values(array_map(static fn (ReconciliationMismatch $mismatch): ReconciliationMismatchType => $mismatch->type(), $mismatches)),
        );
    }
}
