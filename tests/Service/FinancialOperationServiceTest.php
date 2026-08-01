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
use App\Service\PostingService;
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
        $service = new FinancialOperationService($entityManager, new PostingService($entityManager));
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

    public function testFundingAndWithdrawalReversalsRecordLedgerTransactions(): void
    {
        $entityManager = $this->entityManager();
        $service = new FinancialOperationService($entityManager, new PostingService($entityManager));
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

    public function testTransactionFailurePropagatesWithoutReturningPartialResult(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($this->repository());
        $entityManager->method('wrapInTransaction')->willReturnCallback(static function (callable $callback): mixed {
            $callback();
            throw new \RuntimeException('commit failed');
        });
        $service = new FinancialOperationService($entityManager, new PostingService($entityManager));
        $wallet = new Wallet('vendor', 'vendor-1');
        $available = new Account($wallet, 'available', 'USD', AccountCategory::Asset);
        $reserved = new Account($wallet, 'reserved', 'USD', AccountCategory::Reserve);

        $this->expectException(\RuntimeException::class);
        $service->reserve($wallet, $reserved, 500, 'USD', 'reserve-operation-fail', [
            new PostingInstruction($available, -500),
            new PostingInstruction($reserved, 500),
        ]);
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
