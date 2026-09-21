<?php

declare(strict_types=1);

namespace App\Walleting\Tests\Service;

use App\Walleting\Entity\WalletReconciliationMismatch;
use App\Walleting\Entity\WalletReconciliationRun;
use App\Walleting\Enum\WalletReconciliationRunStatus;
use App\Walleting\Service\WalletReconciliationService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class ReconciliationServiceTest extends TestCase
{
    public function testExecuteCreatesDeterministicMismatchesAndCounters(): void
    {
        $persisted = [];
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findOneBy')->willReturn(null);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);
        $entityManager->method('wrapInTransaction')->willReturnCallback(static fn (callable $callback): mixed => $callback());
        $entityManager->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void { $persisted[] = $entity; });

        $run = new WalletReconciliationRun('stripe', 'run-service-1');
        $service = new WalletReconciliationService($entityManager);
        $service->execute($run, [
            ['external_reference' => 'matched', 'amount_minor' => 100, 'currency' => 'usd', 'status' => 'succeeded'],
            ['external_reference' => 'amount', 'amount_minor' => 200, 'currency' => 'USD', 'status' => 'succeeded'],
            ['external_reference' => 'provider-only', 'amount_minor' => 300, 'currency' => 'USD', 'status' => 'succeeded'],
        ], [
            ['external_reference' => 'matched', 'amount_minor' => 100, 'currency' => 'USD', 'status' => 'succeeded'],
            ['external_reference' => 'amount', 'amount_minor' => 250, 'currency' => 'USD', 'status' => 'succeeded'],
            ['external_reference' => 'local-only', 'amount_minor' => 400, 'currency' => 'USD', 'status' => 'succeeded'],
        ]);

        self::assertSame(WalletReconciliationRunStatus::Completed, $run->status());
        self::assertSame(4, $run->checkedCount());
        self::assertSame(1, $run->matchedCount());
        self::assertSame(3, $run->mismatchCount());
        self::assertCount(3, array_filter($persisted, static fn (object $entity): bool => $entity instanceof WalletReconciliationMismatch));
    }

    public function testExecuteRejectsDuplicateExternalReferences(): void
    {
        $repository = $this->createStub(EntityRepository::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);
        $entityManager->method('wrapInTransaction')->willReturnCallback(static fn (callable $callback): mixed => $callback());

        $this->expectException(\DomainException::class);
        (new WalletReconciliationService($entityManager))->execute(new WalletReconciliationRun('stripe', 'run-service-duplicate'), [
            ['external_reference' => 'duplicate', 'amount_minor' => 100, 'currency' => 'USD', 'status' => 'succeeded'],
            ['external_reference' => 'duplicate', 'amount_minor' => 100, 'currency' => 'USD', 'status' => 'succeeded'],
        ], []);
    }
}
