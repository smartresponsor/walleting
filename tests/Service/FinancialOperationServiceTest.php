<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Account;
use App\Entity\Funding;
use App\Entity\PaymentInstrument;
use App\Entity\Wallet;
use App\Entity\Withdrawal;
use App\Enum\AccountCategory;
use App\Enum\FundingStatus;
use App\Enum\PaymentInstrumentType;
use App\Enum\ReservationStatus;
use App\Enum\TransactionType;
use App\Enum\WithdrawalStatus;
use App\Ledger\PostingInstruction;
use App\Service\FinancialOperationService;
use App\Service\OutboxService;
use App\Service\PostingDbalExecutor;
use App\Service\PostingService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class FinancialOperationServiceTest extends TestCase
{
    public function testReserveAndCaptureUseSingleManagedTransactionBoundary(): void
    {
        $entityManager = $this->entityManager();
        $service = new FinancialOperationService($entityManager, $this->postingService($entityManager));
        $wallet = new Wallet('vendor', 'vendor-1');
        $available = new Account($wallet, 'available', 'USD', AccountCategory::Asset);
        $reserved = new Account($wallet, 'reserved', 'USD', AccountCategory::Reserve);

        $reservation = $service->reserve($wallet, $reserved, 500, 'USD', 'reserve-operation-1', [
            new PostingInstruction($available, -500),
            new PostingInstruction($reserved, 500),
        ]);
        self::assertSame(ReservationStatus::Active, $reservation->status());

        $transaction = $service->capture($reservation, 'capture-operation-1', [
            new PostingInstruction($reserved, -500),
            new PostingInstruction($available, 500),
        ]);
        self::assertSame(TransactionType::Capture, $transaction->type());
        self::assertSame(ReservationStatus::Captured, $reservation->status());
    }

    public function testReservationSettlementRejectsMismatchedAmount(): void
    {
        $entityManager = $this->entityManager();
        $service = new FinancialOperationService($entityManager, $this->postingService($entityManager));
        $wallet = new Wallet('vendor', 'vendor-reservation-mismatch');
        $available = new Account($wallet, 'available', 'USD', AccountCategory::Asset);
        $reserved = new Account($wallet, 'reserved', 'USD', AccountCategory::Reserve);
        $reservation = $service->reserve($wallet, $reserved, 500, 'USD', 'reserve-mismatch-1', [
            new PostingInstruction($available, -500),
            new PostingInstruction($reserved, 500),
        ]);

        $this->expectException(\DomainException::class);
        $service->capture($reservation, 'capture-mismatch-1', [
            new PostingInstruction($reserved, -400),
            new PostingInstruction($available, 400),
        ]);
    }

    public function testFundingReversalRejectsDifferentPostingTopology(): void
    {
        $entityManager = $this->entityManager();
        $service = new FinancialOperationService($entityManager, $this->postingService($entityManager));
        $wallet = new Wallet('vendor', 'vendor-reversal-mismatch');
        $cash = new Account($wallet, 'cash', 'USD', AccountCategory::Asset);
        $clearing = new Account($wallet, 'clearing', 'USD', AccountCategory::Clearing);
        $other = new Account($wallet, 'other', 'USD', AccountCategory::Liability);
        $instrument = new PaymentInstrument($wallet, PaymentInstrumentType::Card, 'provider', 'instrument-mismatch', 'Card');
        $funding = new Funding($wallet, $instrument, 700, 'USD', 'funding-mismatch-1');
        $service->succeedFunding($funding, 'funding-mismatch-post-1', [
            new PostingInstruction($cash, 700),
            new PostingInstruction($clearing, -700),
        ]);

        $this->expectException(\DomainException::class);
        $service->reverseFunding($funding, 'funding-mismatch-reverse-1', [
            new PostingInstruction($cash, -700),
            new PostingInstruction($other, 700),
        ]);
    }

    public function testFundingAndWithdrawalReversalsRecordLedgerTransactions(): void
    {
        $entityManager = $this->entityManager();
        $service = new FinancialOperationService($entityManager, $this->postingService($entityManager));
        $wallet = new Wallet('vendor', 'vendor-reversal');
        $cash = new Account($wallet, 'cash', 'USD', AccountCategory::Asset);
        $clearing = new Account($wallet, 'clearing', 'USD', AccountCategory::Clearing);
        $instrument = new PaymentInstrument($wallet, PaymentInstrumentType::Card, 'provider', 'instrument-1', 'Card');

        $funding = new Funding($wallet, $instrument, 700, 'USD', 'funding-1');
        $fundingTransaction = $service->succeedFunding($funding, 'funding-post-1', [
            new PostingInstruction($cash, 700),
            new PostingInstruction($clearing, -700),
        ]);
        $fundingReversal = $service->reverseFunding($funding, 'funding-reverse-1', [
            new PostingInstruction($cash, -700),
            new PostingInstruction($clearing, 700),
        ]);

        self::assertSame(FundingStatus::Reversed, $funding->status());
        self::assertSame($fundingTransaction, $funding->transaction());
        self::assertSame($fundingReversal, $funding->reversalTransaction());

        $withdrawal = new Withdrawal($wallet, $instrument, 400, 'USD', 'withdrawal-1');
        $withdrawalTransaction = $service->succeedWithdrawal($withdrawal, 'withdrawal-post-1', [
            new PostingInstruction($cash, -400),
            new PostingInstruction($clearing, 400),
        ]);
        $withdrawalReversal = $service->reverseWithdrawal($withdrawal, 'withdrawal-reverse-1', [
            new PostingInstruction($cash, 400),
            new PostingInstruction($clearing, -400),
        ]);

        self::assertSame(WithdrawalStatus::Reversed, $withdrawal->status());
        self::assertSame($withdrawalTransaction, $withdrawal->transaction());
        self::assertSame($withdrawalReversal, $withdrawal->reversalTransaction());
    }

    public function testRefundRejectsInverseSourceBeforePosting(): void
    {
        $entityManager = $this->entityManager();
        $service = new FinancialOperationService($entityManager, $this->postingService($entityManager));
        $source = new \App\Entity\LedgerTransaction(TransactionType::Reverse, 'inverse-source-service');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Refund and reverse cannot originate from an inverse transaction.');
        $service->refund($source, 'inverse-source-refund', []);
    }

    public function testTransactionFailurePropagatesWithoutReturningPartialResult(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($this->repository());
        $entityManager->method('wrapInTransaction')->willReturnCallback(static function (callable $callback): mixed {
            $callback();
            throw new \RuntimeException('commit failed');
        });
        $service = new FinancialOperationService($entityManager, $this->postingService($entityManager));
        $wallet = new Wallet('vendor', 'vendor-1');
        $available = new Account($wallet, 'available', 'USD', AccountCategory::Asset);
        $reserved = new Account($wallet, 'reserved', 'USD', AccountCategory::Reserve);

        $this->expectException(\RuntimeException::class);
        $service->reserve($wallet, $reserved, 500, 'USD', 'reserve-operation-fail', [
            new PostingInstruction($available, -500),
            new PostingInstruction($reserved, 500),
        ]);
    }

    private function postingService(EntityManagerInterface $entityManager): PostingService
    {
        $connection = $this->createStub(Connection::class);
        $outboxService = new OutboxService($entityManager, $connection);

        return new PostingService(
            $entityManager,
            $outboxService,
            new PostingDbalExecutor($connection, $outboxService, new \App\Service\PostingRetryPolicy(), new \App\Service\NullPostingTelemetry()),
        );
    }

    private function entityManager(): EntityManagerInterface&MockObject
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($this->repository());
        $entityManager->method('wrapInTransaction')->willReturnCallback(static fn (callable $callback): mixed => $callback());

        return $entityManager;
    }

    private function repository(): EntityRepository
    {
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findOneBy')->willReturn(null);

        return $repository;
    }
}
