<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Funding;
use App\Entity\PaymentInstrument;
use App\Entity\ReconciliationMismatch;
use App\Entity\ReconciliationRun;
use App\Entity\Wallet;
use App\Entity\Withdrawal;
use App\Enum\PaymentInstrumentType;
use App\Enum\ReconciliationMismatchType;
use App\Enum\ReconciliationRunStatus;
use App\Service\ProviderSettlementRecord;
use App\Service\ProviderSettlementReconciliationService;
use App\Service\ReconciliationService;
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
        $withdrawal = new Withdrawal($wallet, $instrument, 400, 'USD', 'provider-settlement-withdrawal');
        $run = new ReconciliationRun('stripe', 'provider-settlement-run');
        foreach ([$wallet, $instrument, $funding, $withdrawal, $run] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();

        $service = new ProviderSettlementReconciliationService(new ReconciliationService($this->entityManager));
        $service->execute($run, [
            new ProviderSettlementRecord('funding', $funding->id()->toRfc4122(), 1000, 'USD', 'pending'),
            new ProviderSettlementRecord('funding', '00000000-0000-0000-0000-000000000001', 250, 'USD', 'pending'),
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
