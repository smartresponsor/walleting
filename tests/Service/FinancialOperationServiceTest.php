<?php

declare(strict_types=1);

namespace App\Walleting\Tests\Service;

use App\Walleting\Entity\Wallet;
use App\Walleting\Entity\WalletAccount;
use App\Walleting\Entity\WalletFinancialOperationLink;
use App\Walleting\Entity\WalletFunding;
use App\Walleting\Entity\WalletLedgerTransaction;
use App\Walleting\Entity\WalletPaymentInstrument;
use App\Walleting\Entity\WalletReservation;
use App\Walleting\Entity\WalletWithdrawal;
use App\Walleting\Enum\WalletAccountCategory;
use App\Walleting\Enum\WalletFundingStatus;
use App\Walleting\Enum\WalletPaymentInstrumentType;
use App\Walleting\Enum\WalletReservationStatus;
use App\Walleting\Enum\WalletTransactionType;
use App\Walleting\Enum\WalletWithdrawalStatus;
use App\Walleting\Service\WalletFinancialOperationService;
use App\Walleting\Service\WalletOutboxService;
use App\Walleting\Service\WalletPostingDbalExecutor;
use App\Walleting\Service\WalletPostingService;
use App\Walleting\ValueObject\Ledger\WalletPostingInstruction;
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
        $service = new WalletFinancialOperationService($entityManager, $this->postingService($entityManager));
        $wallet = new Wallet('vendor', 'vendor-1');
        $available = new WalletAccount($wallet, 'available', 'USD', WalletAccountCategory::Asset);
        $reserved = new WalletAccount($wallet, 'reserved', 'USD', WalletAccountCategory::Reserve);

        $reservation = $service->reserve($wallet, $reserved, 500, 'USD', 'reserve-operation-1', [
            new WalletPostingInstruction($available, -500),
            new WalletPostingInstruction($reserved, 500),
        ]);
        self::assertSame(WalletReservationStatus::Active, $reservation->status());

        $transaction = $service->capture($reservation, 'capture-operation-1', [
            new WalletPostingInstruction($reserved, -500),
            new WalletPostingInstruction($available, 500),
        ]);
        self::assertSame(WalletTransactionType::Capture, $transaction->type());
        self::assertSame(WalletReservationStatus::Captured, $reservation->status());
    }

    public function testReserveReplayReturnsExistingReservation(): void
    {
        $entityManager = $this->statefulEntityManager();
        $service = new WalletFinancialOperationService($entityManager, $this->postingService($entityManager));
        $wallet = new Wallet('vendor', 'reserve-replay');
        $available = new WalletAccount($wallet, 'available', 'USD', WalletAccountCategory::Asset);
        $reserved = new WalletAccount($wallet, 'reserved', 'USD', WalletAccountCategory::Reserve);
        $instructions = [
            new WalletPostingInstruction($available, -500),
            new WalletPostingInstruction($reserved, 500),
        ];

        $first = $service->reserve($wallet, $reserved, 500, 'USD', 'reserve-replay-key', $instructions);
        $replayed = $service->reserve($wallet, $reserved, 500, 'USD', 'reserve-replay-key', $instructions);

        self::assertSame($first, $replayed);
        self::assertSame($first->reserveTransaction(), $replayed->reserveTransaction());
    }

    public function testReservationSettlementRejectsMismatchedAmount(): void
    {
        $entityManager = $this->entityManager();
        $service = new WalletFinancialOperationService($entityManager, $this->postingService($entityManager));
        $wallet = new Wallet('vendor', 'vendor-reservation-mismatch');
        $available = new WalletAccount($wallet, 'available', 'USD', WalletAccountCategory::Asset);
        $reserved = new WalletAccount($wallet, 'reserved', 'USD', WalletAccountCategory::Reserve);
        $reservation = $service->reserve($wallet, $reserved, 500, 'USD', 'reserve-mismatch-1', [
            new WalletPostingInstruction($available, -500),
            new WalletPostingInstruction($reserved, 500),
        ]);

        $this->expectException(\DomainException::class);
        $service->capture($reservation, 'capture-mismatch-1', [
            new WalletPostingInstruction($reserved, -400),
            new WalletPostingInstruction($available, 400),
        ]);
    }

    public function testCaptureReplayReturnsExistingSettlementTransaction(): void
    {
        $entityManager = $this->statefulEntityManager();
        $service = new WalletFinancialOperationService($entityManager, $this->postingService($entityManager));
        $wallet = new Wallet('vendor', 'capture-replay');
        $available = new WalletAccount($wallet, 'available', 'USD', WalletAccountCategory::Asset);
        $reserved = new WalletAccount($wallet, 'reserved', 'USD', WalletAccountCategory::Reserve);
        $reserveTransaction = new WalletLedgerTransaction(WalletTransactionType::Reserve, 'capture-replay-reserve');
        $reservation = new WalletReservation($wallet, $reserved, $reserveTransaction, 500, 'USD', 'capture-replay-reservation');
        $instructions = [
            new WalletPostingInstruction($reserved, -500),
            new WalletPostingInstruction($available, 500),
        ];

        $first = $service->capture($reservation, 'capture-replay-settlement', $instructions);
        $replayed = $service->capture($reservation, 'capture-replay-settlement', $instructions);

        self::assertSame($first, $replayed);
        self::assertSame(WalletReservationStatus::Captured, $reservation->status());
    }

    public function testReleaseReplayReturnsExistingSettlementTransaction(): void
    {
        $entityManager = $this->statefulEntityManager();
        $service = new WalletFinancialOperationService($entityManager, $this->postingService($entityManager));
        $wallet = new Wallet('vendor', 'release-replay');
        $available = new WalletAccount($wallet, 'available', 'USD', WalletAccountCategory::Asset);
        $reserved = new WalletAccount($wallet, 'reserved', 'USD', WalletAccountCategory::Reserve);
        $reserveTransaction = new WalletLedgerTransaction(WalletTransactionType::Reserve, 'release-replay-reserve');
        $reservation = new WalletReservation($wallet, $reserved, $reserveTransaction, 500, 'USD', 'release-replay-reservation');
        $instructions = [
            new WalletPostingInstruction($reserved, -500),
            new WalletPostingInstruction($available, 500),
        ];

        $first = $service->release($reservation, 'release-replay-settlement', $instructions);
        $replayed = $service->release($reservation, 'release-replay-settlement', $instructions);

        self::assertSame($first, $replayed);
        self::assertSame(WalletReservationStatus::Released, $reservation->status());
    }

    public function testReservationSettlementReplayKeyCannotChangeRequestContent(): void
    {
        $entityManager = $this->statefulEntityManager();
        $service = new WalletFinancialOperationService($entityManager, $this->postingService($entityManager));
        $wallet = new Wallet('vendor', 'settlement-replay-conflict');
        $available = new WalletAccount($wallet, 'available', 'USD', WalletAccountCategory::Asset);
        $reserved = new WalletAccount($wallet, 'reserved', 'USD', WalletAccountCategory::Reserve);
        $reserveTransaction = new WalletLedgerTransaction(WalletTransactionType::Reserve, 'settlement-replay-conflict-reserve');
        $reservation = new WalletReservation($wallet, $reserved, $reserveTransaction, 500, 'USD', 'settlement-replay-conflict-reservation');
        $service->capturePartial($reservation, 200, 'settlement-replay-conflict-key', [
            new WalletPostingInstruction($reserved, -200),
            new WalletPostingInstruction($available, 200),
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Idempotency key is already bound to a different financial request.');
        $service->capturePartial($reservation, 300, 'settlement-replay-conflict-key', [
            new WalletPostingInstruction($reserved, -300),
            new WalletPostingInstruction($available, 300),
        ]);
    }

    public function testFundingSuccessReplayReturnsExistingTransaction(): void
    {
        $entityManager = $this->statefulEntityManager();
        $service = new WalletFinancialOperationService($entityManager, $this->postingService($entityManager));
        $wallet = new Wallet('vendor', 'funding-success-replay');
        $cash = new WalletAccount($wallet, 'cash', 'USD', WalletAccountCategory::Asset);
        $clearing = new WalletAccount($wallet, 'clearing', 'USD', WalletAccountCategory::Clearing);
        $instrument = new WalletPaymentInstrument($wallet, WalletPaymentInstrumentType::Card, 'provider', 'funding-success-replay-instrument', 'Card');
        $funding = new WalletFunding($wallet, $instrument, 700, 'USD', 'funding-success-replay');
        $funding->start();
        $instructions = [
            new WalletPostingInstruction($cash, 700),
            new WalletPostingInstruction($clearing, -700),
        ];

        $first = $service->succeedFunding($funding, 'funding-success-replay-post', $instructions);
        $replayed = $service->succeedFunding($funding, 'funding-success-replay-post', $instructions);

        self::assertSame($first, $replayed);
        self::assertSame(WalletFundingStatus::Succeeded, $funding->status());
    }

    public function testWithdrawalSuccessReplayReturnsExistingTransaction(): void
    {
        $entityManager = $this->statefulEntityManager();
        $service = new WalletFinancialOperationService($entityManager, $this->postingService($entityManager));
        $wallet = new Wallet('vendor', 'withdrawal-success-replay');
        $cash = new WalletAccount($wallet, 'cash', 'USD', WalletAccountCategory::Asset);
        $clearing = new WalletAccount($wallet, 'clearing', 'USD', WalletAccountCategory::Clearing);
        $instrument = new WalletPaymentInstrument($wallet, WalletPaymentInstrumentType::BankAccount, 'provider', 'withdrawal-success-replay-instrument', 'Bank');
        $withdrawal = new WalletWithdrawal($wallet, $instrument, 400, 'USD', 'withdrawal-success-replay');
        $withdrawal->start();
        $instructions = [
            new WalletPostingInstruction($cash, -400),
            new WalletPostingInstruction($clearing, 400),
        ];

        $first = $service->succeedWithdrawal($withdrawal, 'withdrawal-success-replay-post', $instructions);
        $replayed = $service->succeedWithdrawal($withdrawal, 'withdrawal-success-replay-post', $instructions);

        self::assertSame($first, $replayed);
        self::assertSame(WalletWithdrawalStatus::Succeeded, $withdrawal->status());
    }

    public function testFundingReversalRejectsDifferentPostingTopology(): void
    {
        $entityManager = $this->entityManager();
        $service = new WalletFinancialOperationService($entityManager, $this->postingService($entityManager));
        $wallet = new Wallet('vendor', 'vendor-reversal-mismatch');
        $cash = new WalletAccount($wallet, 'cash', 'USD', WalletAccountCategory::Asset);
        $clearing = new WalletAccount($wallet, 'clearing', 'USD', WalletAccountCategory::Clearing);
        $other = new WalletAccount($wallet, 'other', 'USD', WalletAccountCategory::Liability);
        $instrument = new WalletPaymentInstrument($wallet, WalletPaymentInstrumentType::Card, 'provider', 'instrument-mismatch', 'Card');
        $funding = new WalletFunding($wallet, $instrument, 700, 'USD', 'funding-mismatch-1');
        $funding->start();
        $service->succeedFunding($funding, 'funding-mismatch-post-1', [
            new WalletPostingInstruction($cash, 700),
            new WalletPostingInstruction($clearing, -700),
        ]);

        $this->expectException(\DomainException::class);
        $service->reverseFunding($funding, 'funding-mismatch-reverse-1', [
            new WalletPostingInstruction($cash, -700),
            new WalletPostingInstruction($other, 700),
        ]);
    }

    public function testFundingAndWithdrawalReversalsRecordLedgerTransactions(): void
    {
        $entityManager = $this->entityManager();
        $service = new WalletFinancialOperationService($entityManager, $this->postingService($entityManager));
        $wallet = new Wallet('vendor', 'vendor-reversal');
        $cash = new WalletAccount($wallet, 'cash', 'USD', WalletAccountCategory::Asset);
        $clearing = new WalletAccount($wallet, 'clearing', 'USD', WalletAccountCategory::Clearing);
        $instrument = new WalletPaymentInstrument($wallet, WalletPaymentInstrumentType::Card, 'provider', 'instrument-1', 'Card');

        $funding = new WalletFunding($wallet, $instrument, 700, 'USD', 'funding-1');
        $funding->start();
        $fundingTransaction = $service->succeedFunding($funding, 'funding-post-1', [
            new WalletPostingInstruction($cash, 700),
            new WalletPostingInstruction($clearing, -700),
        ]);
        $fundingReversal = $service->reverseFunding($funding, 'funding-reverse-1', [
            new WalletPostingInstruction($cash, -700),
            new WalletPostingInstruction($clearing, 700),
        ]);

        self::assertSame(WalletFundingStatus::Reversed, $funding->status());
        self::assertSame($fundingTransaction, $funding->transaction());
        self::assertSame($fundingReversal, $funding->reversalTransaction());

        $withdrawal = new WalletWithdrawal($wallet, $instrument, 400, 'USD', 'withdrawal-1');
        $withdrawal->start();
        $withdrawalTransaction = $service->succeedWithdrawal($withdrawal, 'withdrawal-post-1', [
            new WalletPostingInstruction($cash, -400),
            new WalletPostingInstruction($clearing, 400),
        ]);
        $withdrawalReversal = $service->reverseWithdrawal($withdrawal, 'withdrawal-reverse-1', [
            new WalletPostingInstruction($cash, 400),
            new WalletPostingInstruction($clearing, -400),
        ]);

        self::assertSame(WalletWithdrawalStatus::Reversed, $withdrawal->status());
        self::assertSame($withdrawalTransaction, $withdrawal->transaction());
        self::assertSame($withdrawalReversal, $withdrawal->reversalTransaction());
    }

    public function testFundingReversalReplayReturnsExistingTransactionForSameKey(): void
    {
        $entityManager = $this->entityManager();
        $service = new WalletFinancialOperationService($entityManager, $this->postingService($entityManager));
        $wallet = new Wallet('vendor', 'vendor-funding-reversal-replay');
        $cash = new WalletAccount($wallet, 'cash', 'USD', WalletAccountCategory::Asset);
        $clearing = new WalletAccount($wallet, 'clearing', 'USD', WalletAccountCategory::Clearing);
        $instrument = new WalletPaymentInstrument($wallet, WalletPaymentInstrumentType::Card, 'provider', 'instrument-funding-replay', 'Card');
        $funding = new WalletFunding($wallet, $instrument, 700, 'USD', 'funding-replay');
        $funding->start();
        $service->succeedFunding($funding, 'funding-replay-post', [
            new WalletPostingInstruction($cash, 700),
            new WalletPostingInstruction($clearing, -700),
        ]);
        $instructions = [
            new WalletPostingInstruction($cash, -700),
            new WalletPostingInstruction($clearing, 700),
        ];

        $first = $service->reverseFunding($funding, 'funding-replay-reverse', $instructions);
        $replayed = $service->reverseFunding($funding, 'funding-replay-reverse', $instructions);

        self::assertSame($first, $replayed);
        self::assertSame(WalletFundingStatus::Reversed, $funding->status());
    }

    public function testFundingReversalReplayRejectsDifferentKey(): void
    {
        $entityManager = $this->entityManager();
        $service = new WalletFinancialOperationService($entityManager, $this->postingService($entityManager));
        $wallet = new Wallet('vendor', 'vendor-funding-reversal-conflict');
        $cash = new WalletAccount($wallet, 'cash', 'USD', WalletAccountCategory::Asset);
        $clearing = new WalletAccount($wallet, 'clearing', 'USD', WalletAccountCategory::Clearing);
        $instrument = new WalletPaymentInstrument($wallet, WalletPaymentInstrumentType::Card, 'provider', 'instrument-funding-conflict', 'Card');
        $funding = new WalletFunding($wallet, $instrument, 700, 'USD', 'funding-conflict');
        $funding->start();
        $service->succeedFunding($funding, 'funding-conflict-post', [
            new WalletPostingInstruction($cash, 700),
            new WalletPostingInstruction($clearing, -700),
        ]);
        $instructions = [
            new WalletPostingInstruction($cash, -700),
            new WalletPostingInstruction($clearing, 700),
        ];
        $service->reverseFunding($funding, 'funding-conflict-reverse-1', $instructions);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Funding reversal already exists with a different idempotency key.');
        $service->reverseFunding($funding, 'funding-conflict-reverse-2', $instructions);
    }

    public function testWithdrawalReversalReplayReturnsExistingTransactionForSameKey(): void
    {
        $entityManager = $this->entityManager();
        $service = new WalletFinancialOperationService($entityManager, $this->postingService($entityManager));
        $wallet = new Wallet('vendor', 'vendor-withdrawal-reversal-replay');
        $cash = new WalletAccount($wallet, 'cash', 'USD', WalletAccountCategory::Asset);
        $clearing = new WalletAccount($wallet, 'clearing', 'USD', WalletAccountCategory::Clearing);
        $instrument = new WalletPaymentInstrument($wallet, WalletPaymentInstrumentType::BankAccount, 'provider', 'instrument-withdrawal-replay', 'Bank');
        $withdrawal = new WalletWithdrawal($wallet, $instrument, 400, 'USD', 'withdrawal-replay');
        $withdrawal->start();
        $service->succeedWithdrawal($withdrawal, 'withdrawal-replay-post', [
            new WalletPostingInstruction($cash, -400),
            new WalletPostingInstruction($clearing, 400),
        ]);
        $instructions = [
            new WalletPostingInstruction($cash, 400),
            new WalletPostingInstruction($clearing, -400),
        ];

        $first = $service->reverseWithdrawal($withdrawal, 'withdrawal-replay-reverse', $instructions);
        $replayed = $service->reverseWithdrawal($withdrawal, 'withdrawal-replay-reverse', $instructions);

        self::assertSame($first, $replayed);
        self::assertSame(WalletWithdrawalStatus::Reversed, $withdrawal->status());
    }

    public function testWithdrawalReversalReplayRejectsDifferentKey(): void
    {
        $entityManager = $this->entityManager();
        $service = new WalletFinancialOperationService($entityManager, $this->postingService($entityManager));
        $wallet = new Wallet('vendor', 'vendor-withdrawal-reversal-conflict');
        $cash = new WalletAccount($wallet, 'cash', 'USD', WalletAccountCategory::Asset);
        $clearing = new WalletAccount($wallet, 'clearing', 'USD', WalletAccountCategory::Clearing);
        $instrument = new WalletPaymentInstrument($wallet, WalletPaymentInstrumentType::BankAccount, 'provider', 'instrument-withdrawal-conflict', 'Bank');
        $withdrawal = new WalletWithdrawal($wallet, $instrument, 400, 'USD', 'withdrawal-conflict');
        $withdrawal->start();
        $service->succeedWithdrawal($withdrawal, 'withdrawal-conflict-post', [
            new WalletPostingInstruction($cash, -400),
            new WalletPostingInstruction($clearing, 400),
        ]);
        $instructions = [
            new WalletPostingInstruction($cash, 400),
            new WalletPostingInstruction($clearing, -400),
        ];
        $service->reverseWithdrawal($withdrawal, 'withdrawal-conflict-reverse-1', $instructions);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Withdrawal reversal already exists with a different idempotency key.');
        $service->reverseWithdrawal($withdrawal, 'withdrawal-conflict-reverse-2', $instructions);
    }

    public function testFullRefundReplayReturnsExistingLinkedTransaction(): void
    {
        $entityManager = $this->statefulEntityManager();
        $service = new WalletFinancialOperationService($entityManager, $this->postingService($entityManager));
        $wallet = new Wallet('vendor', 'refund-replay-full');
        $cash = new WalletAccount($wallet, 'cash', 'USD', WalletAccountCategory::Asset);
        $clearing = new WalletAccount($wallet, 'clearing', 'USD', WalletAccountCategory::Clearing);
        $original = new WalletLedgerTransaction(WalletTransactionType::Credit, 'refund-replay-source-full');
        $original->addPosting($cash, 500);
        $original->addPosting($clearing, -500);
        $original->post();
        $instructions = [
            new WalletPostingInstruction($cash, -500),
            new WalletPostingInstruction($clearing, 500),
        ];

        $first = $service->refund($original, 'refund-replay-full', $instructions);
        $replayed = $service->refund($original, 'refund-replay-full', $instructions);

        self::assertSame($first, $replayed);
    }

    public function testPartialRefundReplayReturnsExistingLinkedTransaction(): void
    {
        $entityManager = $this->statefulEntityManager();
        $service = new WalletFinancialOperationService($entityManager, $this->postingService($entityManager));
        $wallet = new Wallet('vendor', 'refund-replay-partial');
        $cash = new WalletAccount($wallet, 'cash', 'USD', WalletAccountCategory::Asset);
        $clearing = new WalletAccount($wallet, 'clearing', 'USD', WalletAccountCategory::Clearing);
        $original = new WalletLedgerTransaction(WalletTransactionType::Credit, 'refund-replay-source-partial');
        $original->addPosting($cash, 500);
        $original->addPosting($clearing, -500);
        $original->post();
        $instructions = [
            new WalletPostingInstruction($cash, -200),
            new WalletPostingInstruction($clearing, 200),
        ];

        $first = $service->refundPartial($original, 200, 'refund-replay-partial', $instructions);
        $replayed = $service->refundPartial($original, 200, 'refund-replay-partial', $instructions);

        self::assertSame($first, $replayed);
    }

    public function testRefundReplayKeyCannotChangeRequestContent(): void
    {
        $entityManager = $this->statefulEntityManager();
        $service = new WalletFinancialOperationService($entityManager, $this->postingService($entityManager));
        $wallet = new Wallet('vendor', 'refund-replay-conflict');
        $cash = new WalletAccount($wallet, 'cash', 'USD', WalletAccountCategory::Asset);
        $clearing = new WalletAccount($wallet, 'clearing', 'USD', WalletAccountCategory::Clearing);
        $original = new WalletLedgerTransaction(WalletTransactionType::Credit, 'refund-replay-source-conflict');
        $original->addPosting($cash, 500);
        $original->addPosting($clearing, -500);
        $original->post();
        $service->refundPartial($original, 200, 'refund-replay-conflict', [
            new WalletPostingInstruction($cash, -200),
            new WalletPostingInstruction($clearing, 200),
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Idempotency key is already bound to a different financial request.');
        $service->refundPartial($original, 300, 'refund-replay-conflict', [
            new WalletPostingInstruction($cash, -300),
            new WalletPostingInstruction($clearing, 300),
        ]);
    }

    public function testRefundRejectsInverseSourceBeforePosting(): void
    {
        $entityManager = $this->entityManager();
        $service = new WalletFinancialOperationService($entityManager, $this->postingService($entityManager));
        $source = new WalletLedgerTransaction(WalletTransactionType::Reverse, 'inverse-source-service');

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
        $service = new WalletFinancialOperationService($entityManager, $this->postingService($entityManager));
        $wallet = new Wallet('vendor', 'vendor-1');
        $available = new WalletAccount($wallet, 'available', 'USD', WalletAccountCategory::Asset);
        $reserved = new WalletAccount($wallet, 'reserved', 'USD', WalletAccountCategory::Reserve);

        $this->expectException(\RuntimeException::class);
        $service->reserve($wallet, $reserved, 500, 'USD', 'reserve-operation-fail', [
            new WalletPostingInstruction($available, -500),
            new WalletPostingInstruction($reserved, 500),
        ]);
    }

    private function statefulEntityManager(): EntityManagerInterface&MockObject
    {
        $transactions = [];
        $links = [];
        $reservations = [];
        $connection = $this->createStub(Connection::class);

        $transactionRepository = $this->createStub(EntityRepository::class);
        $transactionRepository->method('findOneBy')->willReturnCallback(static function (array $criteria) use (&$transactions): ?WalletLedgerTransaction {
            foreach ($transactions as $transaction) {
                if (($criteria['idempotencyKey'] ?? null) === $transaction->idempotencyKey()) {
                    return $transaction;
                }
            }

            return null;
        });

        $linkRepository = $this->createStub(EntityRepository::class);
        $linkRepository->method('findOneBy')->willReturnCallback(static function (array $criteria) use (&$links): ?WalletFinancialOperationLink {
            foreach ($links as $link) {
                if (($criteria['resultTransaction'] ?? null) === $link->resultTransaction()) {
                    return $link;
                }
            }

            return null;
        });

        $reservationRepository = $this->createStub(EntityRepository::class);
        $reservationRepository->method('findOneBy')->willReturnCallback(static function (array $criteria) use (&$reservations): ?WalletReservation {
            foreach ($reservations as $reservation) {
                if (($criteria['idempotencyKey'] ?? null) === $reservation->idempotencyKey()) {
                    return $reservation;
                }
            }

            return null;
        });

        $fallbackRepository = $this->repository();
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $entityManager->method('getRepository')->willReturnCallback(static fn (string $class): EntityRepository => match ($class) {
            WalletLedgerTransaction::class => $transactionRepository,
            WalletFinancialOperationLink::class => $linkRepository,
            WalletReservation::class => $reservationRepository,
            default => $fallbackRepository,
        });
        $entityManager->method('persist')->willReturnCallback(static function (object $entity) use (&$transactions, &$links, &$reservations): void {
            if ($entity instanceof WalletLedgerTransaction) {
                $transactions[] = $entity;
            }
            if ($entity instanceof WalletFinancialOperationLink) {
                $links[] = $entity;
            }
            if ($entity instanceof WalletReservation) {
                $reservations[] = $entity;
            }
        });
        $entityManager->method('wrapInTransaction')->willReturnCallback(static fn (callable $callback): mixed => $callback());

        return $entityManager;
    }

    private function postingService(EntityManagerInterface $entityManager): WalletPostingService
    {
        $connection = $this->createStub(Connection::class);
        $outboxService = new WalletOutboxService($entityManager, $connection);

        return new WalletPostingService(
            $entityManager,
            $outboxService,
            new WalletPostingDbalExecutor($connection, $outboxService, new \App\Walleting\Policy\Posting\WalletPostingRetryPolicy(), new \App\Walleting\Service\WalletNullPostingTelemetry()),
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
