<?php

declare(strict_types=1);

namespace App\Walleting\Tests\Service;

use App\Walleting\Entity\Funding;
use App\Walleting\Entity\PaymentInstrument;
use App\Walleting\Entity\ReconciliationMismatch;
use App\Walleting\Entity\ReconciliationRun;
use App\Walleting\Entity\Wallet;
use App\Walleting\Enum\PaymentInstrumentType;
use App\Walleting\Enum\ReconciliationRunStatus;
use App\Walleting\Service\ProviderSettlementRecord;
use App\Walleting\Service\ProviderSettlementReconciliationService;
use App\Walleting\Service\ReconciliationService;
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
        $instrument = new PaymentInstrument($wallet, PaymentInstrumentType::Card, 'stripe', 'pm_settlement_unit', 'Card');
        $funding = new Funding($wallet, $instrument, 1000, 'USD', 'settlement-unit-funding');
        $run = new ReconciliationRun('stripe', 'settlement-unit-run');

        $record = new ProviderSettlementRecord(' funding ', ' '.$funding->id()->toRfc4122().' ', 1000, 'usd', 'pending');
        self::assertSame('funding', $record->operation);
        self::assertSame('USD', $record->currency);

        (new ProviderSettlementReconciliationService(new ReconciliationService($entityManager)))->execute($run, [$record], [$funding]);

        self::assertSame(ReconciliationRunStatus::Completed, $run->status());
        self::assertSame(1, $run->matchedCount());
        self::assertSame(0, $run->mismatchCount());
        self::assertCount(0, array_filter($persisted, static fn (object $entity): bool => $entity instanceof ReconciliationMismatch));
    }

    public function testLocalOperationProviderMustMatchRunProvider(): void
    {
        $repository = $this->createStub(EntityRepository::class);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);
        $entityManager->method('wrapInTransaction')->willReturnCallback(static fn (callable $callback): mixed => $callback());

        $wallet = new Wallet('vendor', 'settlement-provider-wallet');
        $instrument = new PaymentInstrument($wallet, PaymentInstrumentType::Card, 'adyen', 'pm_settlement_provider', 'Card');
        $funding = new Funding($wallet, $instrument, 500, 'USD', 'settlement-provider-funding');

        $this->expectException(\DomainException::class);
        (new ProviderSettlementReconciliationService(new ReconciliationService($entityManager)))->execute(new ReconciliationRun('stripe', 'settlement-provider-run'), [], [$funding]);
    }
}
