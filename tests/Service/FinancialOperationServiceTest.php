<?php

declare(strict_types=1);

namespace App\Walleting\Tests\Service;

use App\Walleting\Entity\Account;
use App\Walleting\Entity\FinancialOperationLink;
use App\Walleting\Entity\Funding;
use App\Walleting\Entity\LedgerTransaction;
use App\Walleting\Entity\PaymentInstrument;
use App\Walleting\Entity\Reservation;
use App\Walleting\Entity\Wallet;
use App\Walleting\Entity\Withdrawal;
use App\Walleting\Enum\AccountCategory;
use App\Walleting\Enum\FundingStatus;
use App\Walleting\Enum\PaymentInstrumentType;
use App\Walleting\Enum\ReservationStatus;
use App\Walleting\Enum\TransactionType;
use App\Walleting\Enum\WithdrawalStatus;
use App\Walleting\Ledger\PostingInstruction;
use App\Walleting\Service\FinancialOperationService;
use App\Walleting\Service\OutboxService;
use App\Walleting\Service\PostingDbalExecutor;
use App\Walleting\Service\PostingService;
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

    public function testCaptureReplayReturnsExistingSettlementTransaction(): void
    {
        $entityManager = $this->statefulEntityManager();
        $service = new FinancialOperationService($entityManager, $this->postingService($entityManager));
        $wallet = new Wallet('vendor', 'capture-replay');
        $available = new Account($wallet, 'available', 'USD', AccountCategory::Asset);
        $reserved = new Account($wallet, 'reserved', 'USD', AccountCategory::Reserve);
        $reserveTransaction = new LedgerTransaction(TransactionType::Reserve, 'capture-replay-reserve');
        $reservation = new Reservation($wallet, $reserved, $reserveTransaction, 500, 'USD', 'capture-replay-reservation');
        $instructions = [
            new PostingInstruction($reserved, -500),
            new PostingInstruction($available, 500),
        ];

        $first = $service->capture($reservation, 'capture-replay-settlement', $instructions);
        $replayed = $service->capture($reservation, 'capture-replay-settlement', $instructions);

        self::assertSame($first, $replayed);
        self::assertSame(ReservationStatus::Captured, $reservation->status());
    }

    public function testReleaseReplayReturnsExistingSettlementTransaction(): void
    {
        $entityManager = $this->statefulEntityManager();
        $service = new FinancialOperationService($entityManager, $this->postingService($entityManager));
        $wallet = new Wallet('vendor', 'release-replay');
        $available = new Account($wallet, 'available', 'USD', AccountCategory::Asset);
        $reserved = new Account($wallet, 'reserved', 'USD', AccountCategory::Reserve);
        $reserveTransaction = new LedgerTransaction(TransactionType::Reserve, 'release-replay-reserve');
        $reservation = new Reservation($wallet, $reserved, $reserveTransaction, 500, 'USD', 'release-replay-reservation');
        $instructions = [
            new PostingInstruction($reserved, -500),
            new PostingInstruction($available, 500),
        ];

        $first = $service->release($reservation, 'release-replay-settlement', $instructions);
        $replayed = $service->release($reservation, 'release-replay-settlement', $instructions);

        self::assertSame($first, $replayed);
        self::assertSame(ReservationStatus::Released, $reservation->status());
    }

    public function testReservationSettlementReplayKeyCannotChangeRequestContent(): void
    {
        $entityManager = $this->statefulEntityManager();
        $service = new FinancialOperationService($entityManager, $this->postingService($entityManager));
        $wallet = new Wallet('vendor', 'settlement-replay-conflict');
        $available = new Account($wallet, 'available', 'USD', AccountCategory::Asset);
        $reserved = new Account($wallet, 'reserved', 'USD', AccountCategory::Reserve);
        $reserveTransaction = new LedgerTransaction(TransactionType::Reserve, 'settlement-replay-conflict-reserve');
        $reservation = new Reservation($wallet, $reserved, $reserveTransaction, 500, 'USD', 'settlement-replay-conflict-reservation');
        $service->capturePartial($reservation, 200, 'settlement-replay-conflict-key', [
            new PostingInstruction($reserved, -200),
            new PostingInstruction($available, 200),
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Idempotency key is already bound to a different financial request.');
        $service->capturePartial($reservation, 300, 'settlement-replay-conflict-key', [
            new PostingInstruction($reserved, -300),
            new PostingInstruction($available, 300),
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
        $funding->start();
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
        $funding->start();
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
        $withdrawal->start();
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

    public function testFundingReversalReplayReturnsExistingTransactionForSameKey(): void
    {
        $entityManager = $this->entityManager();
        $service = new FinancialOperationService($entityManager, $this->postingService($entityManager));
        $wallet = new Wallet('vendor', 'vendor-funding-reversal-replay');
        $cash = new Account($wallet, 'cash', 'USD', AccountCategory::Asset);
        $clearing = new Account($wallet, 'clearing', 'USD', AccountCategory::Clearing);
        $instrument = new PaymentInstrument($wallet, PaymentInstrumentType::Card, 'provider', 'instrument-funding-replay', 'Card');
        $funding = new Funding($wallet, $instrument, 700, 'USD', 'funding-replay');
        $funding->start();
        $service->succeedFunding($funding, 'funding-replay-post', [
            new PostingInstruction($cash, 700),
            new PostingInstruction($clearing, -700),
        ]);
        $instructions = [
            new PostingInstruction($cash, -700),
            new PostingInstruction($clearing, 700),
        ];

        $first = $service->reverseFunding($funding, 'funding-replay-reverse', $instructions);
        $replayed = $service->reverseFunding($funding, 'funding-replay-reverse', $instructions);

        self::assertSame($first, $replayed);
        self::assertSame(FundingStatus::Reversed, $funding->status());
    }

    public function testFundingReversalReplayRejectsDifferentKey(): void
    {
        $entityManager = $this->entityManager();
        $service = new FinancialOperationService($entityManager, $this->postingService($entityManager));
        $wallet = new Wallet('vendor', 'vendor-funding-reversal-conflict');
        $cash = new Account($wallet, 'cash', 'USD', AccountCategory::Asset);
        $clearing = new Account($wallet, 'clearing', 'USD', AccountCategory::Clearing);
        $instrument = new PaymentInstrument($wallet, PaymentInstrumentType::Card, 'provider', 'instrument-funding-conflict', 'Card');
        $funding = new Funding($wallet, $instrument, 700, 'USD', 'funding-conflict');
        $funding->start();
        $service->succeedFunding($funding, 'funding-conflict-post', [
            new PostingInstruction($cash, 700),
            new PostingInstruction($clearing, -700),
        ]);
        $instructions = [
            new PostingInstruction($cash, -700),
            new PostingInstruction($clearing, 700),
        ];
        $service->reverseFunding($funding, 'funding-conflict-reverse-1', $instructions);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Funding reversal already exists with a different idempotency key.');
        $service->reverseFunding($funding, 'funding-conflict-reverse-2', $instructions);
    }

    public function testWithdrawalReversalReplayReturnsExistingTransactionForSameKey(): void
    {
        $entityManager = $this->entityManager();
        $service = new FinancialOperationService($entityManager, $this->postingService($entityManager));
        $wallet = new Wallet('vendor', 'vendor-withdrawal-reversal-replay');
        $cash = new Account($wallet, 'cash', 'USD', AccountCategory::Asset);
        $clearing = new Account($wallet, 'clearing', 'USD', AccountCategory::Clearing);
        $instrument = new PaymentInstrument($wallet, PaymentInstrumentType::BankAccount, 'provider', 'instrument-withdrawal-replay', 'Bank');
        $withdrawal = new Withdrawal($wallet, $instrument, 400, 'USD', 'withdrawal-replay');
        $withdrawal->start();
        $service->succeedWithdrawal($withdrawal, 'withdrawal-replay-post', [
            new PostingInstruction($cash, -400),
            new PostingInstruction($clearing, 400),
        ]);
        $instructions = [
            new PostingInstruction($cash, 400),
            new PostingInstruction($clearing, -400),
        ];

        $first = $service->reverseWithdrawal($withdrawal, 'withdrawal-replay-reverse', $instructions);
        $replayed = $service->reverseWithdrawal($withdrawal, 'withdrawal-replay-reverse', $instructions);

        self::assertSame($first, $replayed);
        self::assertSame(WithdrawalStatus::Reversed, $withdrawal->status());
    }

    public function testWithdrawalReversalReplayRejectsDifferentKey(): void
    {
        $entityManager = $this->entityManager();
        $service = new FinancialOperationService($entityManager, $this->postingService($entityManager));
        $wallet = new Wallet('vendor', 'vendor-withdrawal-reversal-conflict');
        $cash = new Account($wallet, 'cash', 'USD', AccountCategory::Asset);
        $clearing = new Account($wallet, 'clearing', 'USD', AccountCategory::Clearing);
        $instrument = new PaymentInstrument($wallet, PaymentInstrumentType::BankAccount, 'provider', 'instrument-withdrawal-conflict', 'Bank');
        $withdrawal = new Withdrawal($wallet, $instrument, 400, 'USD', 'withdrawal-conflict');
        $withdrawal->start();
        $service->succeedWithdrawal($withdrawal, 'withdrawal-conflict-post', [
            new PostingInstruction($cash, -400),
            new PostingInstruction($clearing, 400),
        ]);
        $instructions = [
            new PostingInstruction($cash, 400),
            new PostingInstruction($clearing, -400),
        ];
        $service->reverseWithdrawal($withdrawal, 'withdrawal-conflict-reverse-1', $instructions);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Withdrawal reversal already exists with a different idempotency key.');
        $service->reverseWithdrawal($withdrawal, 'withdrawal-conflict-reverse-2', $instructions);
    }

    public function testFullRefundReplayReturnsExistingLinkedTransaction(): void
    {
        $entityManager = $this->statefulEntityManager();
        $service = new FinancialOperationService($entityManager, $this->postingService($entityManager));
        $wallet = new Wallet('vendor', 'refund-replay-full');
        $cash = new Account($wallet, 'cash', 'USD', AccountCategory::Asset);
        $clearing = new Account($wallet, 'clearing', 'USD', AccountCategory::Clearing);
        $original = new LedgerTransaction(TransactionType::Credit, 'refund-replay-source-full');
        $original->addPosting($cash, 500);
        $original->addPosting($clearing, -500);
        $original->post();
        $instructions = [
            new PostingInstruction($cash, -500),
            new PostingInstruction($clearing, 500),
        ];

        $first = $service->refund($original, 'refund-replay-full', $instructions);
        $replayed = $service->refund($original, 'refund-replay-full', $instructions);

        self::assertSame($first, $replayed);
    }

    public function testPartialRefundReplayReturnsExistingLinkedTransaction(): void
    {
        $entityManager = $this->statefulEntityManager();
        $service = new FinancialOperationService($entityManager, $this->postingService($entityManager));
        $wallet = new Wallet('vendor', 'refund-replay-partial');
        $cash = new Account($wallet, 'cash', 'USD', AccountCategory::Asset);
        $clearing = new Account($wallet, 'clearing', 'USD', AccountCategory::Clearing);
        $original = new LedgerTransaction(TransactionType::Credit, 'refund-replay-source-partial');
        $original->addPosting($cash, 500);
        $original->addPosting($clearing, -500);
        $original->post();
        $instructions = [
            new PostingInstruction($cash, -200),
            new PostingInstruction($clearing, 200),
        ];

        $first = $service->refundPartial($original, 200, 'refund-replay-partial', $instructions);
        $replayed = $service->refundPartial($original, 200, 'refund-replay-partial', $instructions);

        self::assertSame($first, $replayed);
    }

    public function testRefundReplayKeyCannotChangeRequestContent(): void
    {
        $entityManager = $this->statefulEntityManager();
        $service = new FinancialOperationService($entityManager, $this->postingService($entityManager));
        $wallet = new Wallet('vendor', 'refund-replay-conflict');
        $cash = new Account($wallet, 'cash', 'USD', AccountCategory::Asset);
        $clearing = new Account($wallet, 'clearing', 'USD', AccountCategory::Clearing);
        $original = new LedgerTransaction(TransactionType::Credit, 'refund-replay-source-conflict');
        $original->addPosting($cash, 500);
        $original->addPosting($clearing, -500);
        $original->post();
        $service->refundPartial($original, 200, 'refund-replay-conflict', [
            new PostingInstruction($cash, -200),
            new PostingInstruction($clearing, 200),
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Idempotency key is already bound to a different financial request.');
        $service->refundPartial($original, 300, 'refund-replay-conflict', [
            new PostingInstruction($cash, -300),
            new PostingInstruction($clearing, 300),
        ]);
    }

    public function testRefundRejectsInverseSourceBeforePosting(): void
    {
        $entityManager = $this->entityManager();
        $service = new FinancialOperationService($entityManager, $this->postingService($entityManager));
        $source = new LedgerTransaction(TransactionType::Reverse, 'inverse-source-service');

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

    private function statefulEntityManager(): EntityManagerInterface&MockObject
    {
        $transactions = [];
        $links = [];
        $connection = $this->createStub(Connection::class);

        $transactionRepository = $this->createStub(EntityRepository::class);
        $transactionRepository->method('findOneBy')->willReturnCallback(static function (array $criteria) use (&$transactions): ?LedgerTransaction {
            foreach ($transactions as $transaction) {
                if (($criteria['idempotencyKey'] ?? null) === $transaction->idempotencyKey()) {
                    return $transaction;
                }
            }

            return null;
        });

        $linkRepository = $this->createStub(EntityRepository::class);
        $linkRepository->method('findOneBy')->willReturnCallback(static function (array $criteria) use (&$links): ?FinancialOperationLink {
            foreach ($links as $link) {
                if (($criteria['resultTransaction'] ?? null) === $link->resultTransaction()) {
                    return $link;
                }
            }

            return null;
        });

        $fallbackRepository = $this->repository();
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $entityManager->method('getRepository')->willReturnCallback(static fn (string $class): EntityRepository => match ($class) {
            LedgerTransaction::class => $transactionRepository,
            FinancialOperationLink::class => $linkRepository,
            default => $fallbackRepository,
        });
        $entityManager->method('persist')->willReturnCallback(static function (object $entity) use (&$transactions, &$links): void {
            if ($entity instanceof LedgerTransaction) {
                $transactions[] = $entity;
            }
            if ($entity instanceof FinancialOperationLink) {
                $links[] = $entity;
            }
        });
        $entityManager->method('wrapInTransaction')->willReturnCallback(static fn (callable $callback): mixed => $callback());

        return $entityManager;
    }

    private function postingService(EntityManagerInterface $entityManager): PostingService
    {
        $connection = $this->createStub(Connection::class);
        $outboxService = new OutboxService($entityManager, $connection);

        return new PostingService(
            $entityManager,
            $outboxService,
            new PostingDbalExecutor($connection, $outboxService, new \App\Walleting\Service\PostingRetryPolicy(), new \App\Walleting\Service\NullPostingTelemetry()),
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
