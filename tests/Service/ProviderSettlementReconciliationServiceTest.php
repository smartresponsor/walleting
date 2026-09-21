<?php

declare(strict_types=1);

namespace App\Walleting\Tests\Service;

use App\Walleting\Entity\Wallet;
use App\Walleting\Entity\WalletFunding;
use App\Walleting\Entity\WalletPaymentInstrument;
use App\Walleting\Entity\WalletReconciliationMismatch;
use App\Walleting\Entity\WalletReconciliationRun;
use App\Walleting\Enum\WalletPaymentInstrumentType;
use App\Walleting\Enum\WalletReconciliationRunStatus;
use App\Walleting\Service\WalletProviderSettlementReconciliationService;
use App\Walleting\Service\WalletProviderSettlementRecord;
use App\Walleting\Service\WalletReconciliationService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

final class ProviderSettlementReconciliationServiceTest extends TestCase
{
    public function testProviderSettlementRecordsAreCanonicalAndComparedAgainstLocalOperations(): void
    {
        $persisted = [];
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findOneBy')->willReturn(null);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);
        $entityManager->method('wrapInTransaction')->willReturnCallback(static fn (callable $callback): mixed => $callback());
        $entityManager->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void { $persisted[] = $entity; });

        $wallet = new Wallet('vendor', 'settlement-unit-wallet');
        $instrument = new WalletPaymentInstrument($wallet, WalletPaymentInstrumentType::Card, 'stripe', 'pm_settlement_unit', 'Card');
        $funding = new WalletFunding($wallet, $instrument, 1000, 'USD', 'settlement-unit-funding');
        $funding->start();
        $funding->bindProviderOperationReference('ch_settlement_unit');
        $run = new WalletReconciliationRun('stripe', 'settlement-unit-run');

        $record = new WalletProviderSettlementRecord(' funding ', ' ch_settlement_unit ', 1000, 'usd', 'processing');
        self::assertSame('funding', $record->operation);
        self::assertSame('USD', $record->currency);

        (new WalletProviderSettlementReconciliationService(new WalletReconciliationService($entityManager)))->execute($run, [$record], [$funding]);

        self::assertSame(WalletReconciliationRunStatus::Completed, $run->status());
        self::assertSame(1, $run->matchedCount());
        self::assertSame(0, $run->mismatchCount());
        self::assertCount(0, array_filter($persisted, static fn (object $entity): bool => $entity instanceof WalletReconciliationMismatch));
    }

    public function testSettlementReconciliationRequiresProviderOperationReference(): void
    {
        $repository = $this->createStub(EntityRepository::class);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);
        $entityManager->method('wrapInTransaction')->willReturnCallback(static fn (callable $callback): mixed => $callback());

        $wallet = new Wallet('vendor', 'settlement-reference-wallet');
        $instrument = new WalletPaymentInstrument($wallet, WalletPaymentInstrumentType::Card, 'stripe', 'pm_settlement_reference', 'Card');
        $funding = new WalletFunding($wallet, $instrument, 500, 'USD', 'settlement-reference-funding');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Provider settlement reconciliation requires a bound provider operation reference.');
        (new WalletProviderSettlementReconciliationService(new WalletReconciliationService($entityManager)))->execute(new WalletReconciliationRun('stripe', 'settlement-reference-run'), [], [$funding]);
    }

    public function testLocalOperationProviderMustMatchRunProvider(): void
    {
        $repository = $this->createStub(EntityRepository::class);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);
        $entityManager->method('wrapInTransaction')->willReturnCallback(static fn (callable $callback): mixed => $callback());

        $wallet = new Wallet('vendor', 'settlement-provider-wallet');
        $instrument = new WalletPaymentInstrument($wallet, WalletPaymentInstrumentType::Card, 'adyen', 'pm_settlement_provider', 'Card');
        $funding = new WalletFunding($wallet, $instrument, 500, 'USD', 'settlement-provider-funding');

        $this->expectException(\DomainException::class);
        (new WalletProviderSettlementReconciliationService(new WalletReconciliationService($entityManager)))->execute(new WalletReconciliationRun('stripe', 'settlement-provider-run'), [], [$funding]);
    }
}
