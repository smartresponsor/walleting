<?php

declare(strict_types=1);

namespace App\Walleting\Service;

use App\Walleting\Entity\Wallet;
use App\Walleting\Entity\WalletAccount;
use App\Walleting\Entity\WalletFinancialOperationLink;
use App\Walleting\Entity\WalletFunding;
use App\Walleting\Entity\WalletLedgerTransaction;
use App\Walleting\Entity\WalletReservation;
use App\Walleting\Entity\WalletWithdrawal;
use App\Walleting\Enum\WalletFundingStatus;
use App\Walleting\Enum\WalletTransactionType;
use App\Walleting\Enum\WalletWithdrawalStatus;
use App\Walleting\ValueObject\Ledger\WalletFeeAllocation;
use App\Walleting\ValueObject\Ledger\WalletPostingInstruction;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final readonly class WalletFinancialOperationService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private WalletPostingService $postingService,
        private ?WalletOutboxService $outboxService = null,
        private ?WalletFeePostingComposer $feePostingComposer = null,
    ) {
    }

    /** @param non-empty-list<WalletPostingInstruction> $instructions */
    public function reserve(Wallet $wallet, WalletAccount $account, int $amountMinor, string $currency, string $idempotencyKey, array $instructions, ?\DateTimeImmutable $expiresAt = null): WalletReservation
    {
        return $this->entityManager->wrapInTransaction(function () use ($wallet, $account, $amountMinor, $currency, $idempotencyKey, $instructions, $expiresAt): WalletReservation {
            $transaction = $this->postingService->postManaged(WalletTransactionType::Reserve, $idempotencyKey, $instructions, ['operation' => 'reserve']);
            $existing = $this->entityManager->getRepository(WalletReservation::class)->findOneBy(['idempotencyKey' => trim($idempotencyKey)]);
            if ($existing instanceof WalletReservation) {
                if ($existing->wallet() !== $wallet || $existing->account() !== $account || $existing->reserveTransaction() !== $transaction || $existing->amountMinor() !== $amountMinor || $existing->currency() !== strtoupper(trim($currency))) {
                    throw new \DomainException('Idempotent reservation replay conflicts with the existing reservation.');
                }

                return $existing;
            }

            $reservation = new WalletReservation($wallet, $account, $transaction, $amountMinor, $currency, $idempotencyKey, $expiresAt);
            $this->entityManager->persist($reservation);
            $this->emitReservation('wallet.reservation.created', $reservation, $transaction);
            $this->entityManager->flush();

            return $reservation;
        });
    }

    /** @param non-empty-list<WalletPostingInstruction> $instructions */
    public function capture(WalletReservation $reservation, string $idempotencyKey, array $instructions): WalletLedgerTransaction
    {
        return $this->capturePartial($reservation, $reservation->amountMinor(), $idempotencyKey, $instructions);
    }

    /** @param non-empty-list<WalletPostingInstruction> $instructions */
    public function capturePartial(WalletReservation $reservation, int $amountMinor, string $idempotencyKey, array $instructions): WalletLedgerTransaction
    {
        return $this->transitionReservation($reservation, $amountMinor, $idempotencyKey, $instructions, WalletTransactionType::Capture, 'capture');
    }

    /** @param list<WalletFeeAllocation> $fees */
    public function captureWithFees(WalletReservation $reservation, WalletAccount $netDestination, array $fees, string $idempotencyKey): WalletLedgerTransaction
    {
        return $this->capturePartialWithFees($reservation, $reservation->amountMinor(), $netDestination, $fees, $idempotencyKey);
    }

    /** @param list<WalletFeeAllocation> $fees */
    public function capturePartialWithFees(WalletReservation $reservation, int $amountMinor, WalletAccount $netDestination, array $fees, string $idempotencyKey): WalletLedgerTransaction
    {
        $plan = ($this->feePostingComposer ?? new WalletFeePostingComposer())->compose($reservation->account(), $netDestination, $amountMinor, $fees);

        return $this->transitionReservation(
            $reservation,
            $amountMinor,
            $idempotencyKey,
            $plan->instructions,
            WalletTransactionType::Capture,
            'capture',
            ['settlement' => $plan->metadata],
        );
    }

    /** @param non-empty-list<WalletPostingInstruction> $instructions */
    public function release(WalletReservation $reservation, string $idempotencyKey, array $instructions): WalletLedgerTransaction
    {
        return $this->releasePartial($reservation, $reservation->amountMinor(), $idempotencyKey, $instructions);
    }

    /** @param non-empty-list<WalletPostingInstruction> $instructions */
    public function releasePartial(WalletReservation $reservation, int $amountMinor, string $idempotencyKey, array $instructions): WalletLedgerTransaction
    {
        return $this->transitionReservation($reservation, $amountMinor, $idempotencyKey, $instructions, WalletTransactionType::Release, 'release');
    }

    /** @param non-empty-list<WalletPostingInstruction> $instructions */
    public function refund(WalletLedgerTransaction $original, string $idempotencyKey, array $instructions): WalletLedgerTransaction
    {
        $this->assertInverseSourceAllowed($original);
        $this->assertExactInverse($original, $instructions);

        return $this->linkedPosting(WalletTransactionType::Refund, $original, $this->transactionAmount($original), $idempotencyKey, $instructions);
    }

    /** @param non-empty-list<WalletPostingInstruction> $instructions */
    public function refundPartial(WalletLedgerTransaction $original, int $amountMinor, string $idempotencyKey, array $instructions): WalletLedgerTransaction
    {
        $this->assertInverseSourceAllowed($original);
        $this->assertPartialInverse($original, $amountMinor, $instructions);

        return $this->linkedPosting(WalletTransactionType::Refund, $original, $amountMinor, $idempotencyKey, $instructions);
    }

    /** @param non-empty-list<WalletPostingInstruction> $instructions */
    public function refundPartialAllocated(WalletLedgerTransaction $original, int $amountMinor, string $idempotencyKey, array $instructions): WalletLedgerTransaction
    {
        $this->assertInverseSourceAllowed($original);
        $this->assertAllocatedPartialInverse($original, $amountMinor, $instructions);

        return $this->linkedPosting(WalletTransactionType::Refund, $original, $amountMinor, $idempotencyKey, $instructions);
    }

    /** @param non-empty-list<WalletPostingInstruction> $instructions */
    public function reverse(WalletLedgerTransaction $original, string $idempotencyKey, array $instructions): WalletLedgerTransaction
    {
        $this->assertInverseSourceAllowed($original);
        $this->assertExactInverse($original, $instructions);

        return $this->linkedPosting(WalletTransactionType::Reverse, $original, $this->transactionAmount($original), $idempotencyKey, $instructions);
    }

    /** @param non-empty-list<WalletPostingInstruction> $instructions */
    public function succeedFunding(WalletFunding $funding, string $idempotencyKey, array $instructions): WalletLedgerTransaction
    {
        return $this->entityManager->wrapInTransaction(function () use ($funding, $idempotencyKey, $instructions): WalletLedgerTransaction {
            $this->entityManager->lock($funding, LockMode::PESSIMISTIC_WRITE);
            $transaction = $this->postingService->postManaged(WalletTransactionType::Credit, $idempotencyKey, $instructions, ['operation' => 'funding', 'funding_key' => $funding->idempotencyKey()]);
            $existing = $funding->transaction();
            if ($existing instanceof WalletLedgerTransaction) {
                if ($existing !== $transaction || !in_array($funding->status(), [WalletFundingStatus::Succeeded, WalletFundingStatus::Reversed], true)) {
                    throw new \DomainException('Idempotent funding success replay conflicts with the existing funding result.');
                }

                return $existing;
            }

            $funding->succeed($transaction);
            $this->emitFunding('wallet.funding.succeeded', $funding, $transaction);
            $this->entityManager->flush();

            return $transaction;
        });
    }

    /** @param non-empty-list<WalletPostingInstruction> $instructions */
    public function succeedWithdrawal(WalletWithdrawal $withdrawal, string $idempotencyKey, array $instructions): WalletLedgerTransaction
    {
        return $this->entityManager->wrapInTransaction(function () use ($withdrawal, $idempotencyKey, $instructions): WalletLedgerTransaction {
            $this->entityManager->lock($withdrawal, LockMode::PESSIMISTIC_WRITE);
            $transaction = $this->postingService->postManaged(WalletTransactionType::Debit, $idempotencyKey, $instructions, ['operation' => 'withdrawal', 'withdrawal_key' => $withdrawal->idempotencyKey()]);
            $existing = $withdrawal->transaction();
            if ($existing instanceof WalletLedgerTransaction) {
                if ($existing !== $transaction || !in_array($withdrawal->status(), [WalletWithdrawalStatus::Succeeded, WalletWithdrawalStatus::Reversed], true)) {
                    throw new \DomainException('Idempotent withdrawal success replay conflicts with the existing withdrawal result.');
                }

                return $existing;
            }

            $withdrawal->succeed($transaction);
            $this->emitWithdrawal('wallet.withdrawal.succeeded', $withdrawal, $transaction);
            $this->entityManager->flush();

            return $transaction;
        });
    }

    /** @param non-empty-list<WalletPostingInstruction> $instructions */
    public function reverseFunding(WalletFunding $funding, string $idempotencyKey, array $instructions): WalletLedgerTransaction
    {
        return $this->entityManager->wrapInTransaction(function () use ($funding, $idempotencyKey, $instructions): WalletLedgerTransaction {
            $this->entityManager->lock($funding, LockMode::PESSIMISTIC_WRITE);
            $original = $funding->transaction();
            if (!$original instanceof WalletLedgerTransaction) {
                throw new \LogicException('Funding must have a successful ledger transaction before reversal.');
            }
            $this->assertExactInverse($original, $instructions);

            if (WalletFundingStatus::Reversed === $funding->status()) {
                $existing = $funding->reversalTransaction();
                if ($existing instanceof WalletLedgerTransaction && $existing->idempotencyKey() === trim($idempotencyKey)) {
                    return $existing;
                }

                throw new \DomainException('Funding reversal already exists with a different idempotency key.');
            }

            $transaction = $this->postingService->postManaged(WalletTransactionType::Reverse, $idempotencyKey, $instructions, ['operation' => 'funding_reversal', 'funding_key' => $funding->idempotencyKey(), 'original_transaction_id' => $original->id()->toRfc4122()]);
            $this->entityManager->persist(new WalletFinancialOperationLink(WalletTransactionType::Reverse, $original, $transaction, $this->transactionAmount($original)));
            $funding->reverse($transaction);
            $this->emitFunding('wallet.funding.reversed', $funding, $transaction);
            $this->entityManager->flush();

            return $transaction;
        });
    }

    /** @param non-empty-list<WalletPostingInstruction> $instructions */
    public function reverseWithdrawal(WalletWithdrawal $withdrawal, string $idempotencyKey, array $instructions): WalletLedgerTransaction
    {
        return $this->entityManager->wrapInTransaction(function () use ($withdrawal, $idempotencyKey, $instructions): WalletLedgerTransaction {
            $this->entityManager->lock($withdrawal, LockMode::PESSIMISTIC_WRITE);
            $original = $withdrawal->transaction();
            if (!$original instanceof WalletLedgerTransaction) {
                throw new \LogicException('Withdrawal must have a successful ledger transaction before reversal.');
            }
            $this->assertExactInverse($original, $instructions);

            if (WalletWithdrawalStatus::Reversed === $withdrawal->status()) {
                $existing = $withdrawal->reversalTransaction();
                if ($existing instanceof WalletLedgerTransaction && $existing->idempotencyKey() === trim($idempotencyKey)) {
                    return $existing;
                }

                throw new \DomainException('Withdrawal reversal already exists with a different idempotency key.');
            }

            $transaction = $this->postingService->postManaged(WalletTransactionType::Reverse, $idempotencyKey, $instructions, ['operation' => 'withdrawal_reversal', 'withdrawal_key' => $withdrawal->idempotencyKey(), 'original_transaction_id' => $original->id()->toRfc4122()]);
            $this->entityManager->persist(new WalletFinancialOperationLink(WalletTransactionType::Reverse, $original, $transaction, $this->transactionAmount($original)));
            $withdrawal->reverse($transaction);
            $this->emitWithdrawal('wallet.withdrawal.reversed', $withdrawal, $transaction);
            $this->entityManager->flush();

            return $transaction;
        });
    }

    /** @param non-empty-list<WalletPostingInstruction> $instructions */
    private function transitionReservation(WalletReservation $reservation, int $amountMinor, string $idempotencyKey, array $instructions, WalletTransactionType $type, string $operation, array $metadata = []): WalletLedgerTransaction
    {
        $this->assertReservationSettlement($reservation, $amountMinor, $instructions);

        return $this->entityManager->wrapInTransaction(function () use ($reservation, $amountMinor, $idempotencyKey, $instructions, $type, $operation, $metadata): WalletLedgerTransaction {
            $this->entityManager->lock($reservation, LockMode::PESSIMISTIC_WRITE);
            $transaction = $this->postingService->postManaged($type, $idempotencyKey, $instructions, array_replace_recursive(['operation' => $operation, 'reservation_key' => $reservation->idempotencyKey(), 'amount_minor' => $amountMinor], $metadata));
            $existingLink = $this->entityManager->getRepository(WalletFinancialOperationLink::class)->findOneBy(['resultTransaction' => $transaction]);
            if ($existingLink instanceof WalletFinancialOperationLink) {
                if ($existingLink->reservation() !== $reservation || $existingLink->sourceTransaction() !== $reservation->reserveTransaction() || $existingLink->operationType() !== $type || $existingLink->amountMinor() !== $amountMinor) {
                    throw new \DomainException('Idempotent reservation settlement replay conflicts with the existing operation link.');
                }

                return $transaction;
            }

            [$capturedMinor, $releasedMinor] = $this->reservationSettlementTotals($reservation);
            if ($capturedMinor + $releasedMinor + $amountMinor > $reservation->amountMinor()) {
                throw new \DomainException('Reservation settlement exceeds the remaining reserved amount.');
            }

            $this->entityManager->persist(new WalletFinancialOperationLink($type, $reservation->reserveTransaction(), $transaction, $amountMinor, $reservation));
            'capture' === $operation ? $capturedMinor += $amountMinor : $releasedMinor += $amountMinor;
            $reservation->recordSettlementProgress($capturedMinor, $releasedMinor);
            $this->emitReservation('wallet.reservation.'.$operation.'d', $reservation, $transaction);
            $this->entityManager->flush();

            return $transaction;
        });
    }

    private function assertInverseSourceAllowed(WalletLedgerTransaction $original): void
    {
        if (in_array($original->type(), [WalletTransactionType::Refund, WalletTransactionType::Reverse], true)) {
            throw new \DomainException('Refund and reverse cannot originate from an inverse transaction.');
        }
    }

    /** @param non-empty-list<WalletPostingInstruction> $instructions */
    private function assertAllocatedPartialInverse(WalletLedgerTransaction $original, int $amountMinor, array $instructions): void
    {
        if ($amountMinor <= 0) {
            throw new \InvalidArgumentException('Partial refund amount must be positive.');
        }

        $source = $this->postingAmountsByAccount($original);
        [$actual, $positive] = $this->allocatedRefundAmounts($instructions, $source);
        if ($positive !== $amountMinor || 0 !== array_sum($actual)) {
            throw new \DomainException('Allocated refund postings must balance to the requested refund amount.');
        }

        $this->assertAllocatedRefundLegBounds($source, $actual);
    }

    /** @return array<string, int> */
    private function postingAmountsByAccount(WalletLedgerTransaction $transaction): array
    {
        $amounts = [];
        foreach ($transaction->postings() as $posting) {
            $accountId = $posting->account()->id()->toRfc4122();
            $amounts[$accountId] = ($amounts[$accountId] ?? 0) + $posting->amountMinor();
        }

        return $amounts;
    }

    /**
     * @param non-empty-list<WalletPostingInstruction> $instructions
     * @param array<string, int>                       $source
     *
     * @return array{array<string, int>, int}
     */
    private function allocatedRefundAmounts(array $instructions, array $source): array
    {
        $actual = [];
        $positive = 0;
        foreach ($instructions as $instruction) {
            $accountId = $instruction->account->id()->toRfc4122();
            if (!array_key_exists($accountId, $source)) {
                throw new \DomainException('Allocated refund may only use accounts from the original transaction.');
            }
            $actual[$accountId] = ($actual[$accountId] ?? 0) + $instruction->amountMinor;
            if ($instruction->amountMinor > 0) {
                $positive += $instruction->amountMinor;
            }
        }

        return [$actual, $positive];
    }

    /**
     * @param array<string, int> $source
     * @param array<string, int> $actual
     */
    private function assertAllocatedRefundLegBounds(array $source, array $actual): void
    {
        foreach ($actual as $accountId => $amount) {
            $sourceAmount = $source[$accountId];
            if (0 === $amount || 0 === $sourceAmount || ($sourceAmount > 0 && ($amount > 0 || -$amount > $sourceAmount)) || ($sourceAmount < 0 && ($amount < 0 || $amount > -$sourceAmount))) {
                throw new \DomainException('Allocated refund must invert original account legs without exceeding them.');
            }
        }
    }

    /** @param non-empty-list<WalletPostingInstruction> $instructions */
    private function assertPartialInverse(WalletLedgerTransaction $original, int $amountMinor, array $instructions): void
    {
        if ($amountMinor <= 0) {
            throw new \InvalidArgumentException('Partial refund amount must be positive.');
        }

        $legs = [];
        foreach ($original->postings() as $posting) {
            $accountId = $posting->account()->id()->toRfc4122();
            $legs[$accountId] = ($legs[$accountId] ?? 0) + $posting->amountMinor();
        }
        $legs = array_filter($legs, static fn (int $amount): bool => 0 !== $amount);
        if (2 !== count($legs)) {
            throw new \DomainException('Partial refund currently requires a two-sided source transaction.');
        }
        $positive = array_filter($legs, static fn (int $amount): bool => $amount > 0);
        $negative = array_filter($legs, static fn (int $amount): bool => $amount < 0);
        if (1 !== count($positive) || 1 !== count($negative) || reset($positive) !== -reset($negative) || $amountMinor > (int) reset($positive)) {
            throw new \DomainException('Partial refund source topology or amount is invalid.');
        }

        $expected = [];
        foreach ($legs as $accountId => $amount) {
            $expected[$accountId] = $amount > 0 ? -$amountMinor : $amountMinor;
        }
        $actual = [];
        foreach ($instructions as $instruction) {
            $accountId = $instruction->account->id()->toRfc4122();
            $actual[$accountId] = ($actual[$accountId] ?? 0) + $instruction->amountMinor;
        }
        ksort($expected);
        ksort($actual);
        if ($expected !== $actual) {
            throw new \DomainException('Partial refund postings must invert the requested amount on the original two accounts.');
        }
    }

    /** @param non-empty-list<WalletPostingInstruction> $instructions */
    private function assertRefundLegCapacity(WalletLedgerTransaction $original, array $instructions): void
    {
        $source = [];
        foreach ($original->postings() as $posting) {
            $accountId = $posting->account()->id()->toRfc4122();
            $source[$accountId] = ($source[$accountId] ?? 0) + $posting->amountMinor();
        }

        $existing = [];
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            "SELECT p.account_id, COALESCE(SUM(p.amount_minor), 0) AS refunded_minor FROM financial_operation_link l JOIN posting p ON p.transaction_id = l.result_transaction_id WHERE l.source_transaction_id = ? AND l.operation_type = 'refund' GROUP BY p.account_id",
            [$original->id()->toRfc4122()],
        );
        foreach ($rows as $row) {
            $existing[(string) $row['account_id']] = (int) $row['refunded_minor'];
        }

        $next = $existing;
        foreach ($instructions as $instruction) {
            $accountId = $instruction->account->id()->toRfc4122();
            $next[$accountId] = ($next[$accountId] ?? 0) + $instruction->amountMinor;
        }
        foreach ($next as $accountId => $amount) {
            $sourceAmount = $source[$accountId] ?? null;
            if (null === $sourceAmount || 0 === $sourceAmount || ($sourceAmount > 0 && ($amount > 0 || -$amount > $sourceAmount)) || ($sourceAmount < 0 && ($amount < 0 || $amount > -$sourceAmount))) {
                throw new \DomainException('Cumulative refund exceeds an original transaction account leg.');
            }
        }
    }

    private function transactionAmount(WalletLedgerTransaction $transaction): int
    {
        $amountMinor = 0;
        foreach ($transaction->postings() as $posting) {
            if ($posting->amountMinor() > 0) {
                $amountMinor += $posting->amountMinor();
            }
        }
        if ($amountMinor <= 0) {
            throw new \DomainException('Ledger transaction does not expose a positive financial amount.');
        }

        return $amountMinor;
    }

    /** @param non-empty-list<WalletPostingInstruction> $instructions */
    private function assertExactInverse(WalletLedgerTransaction $original, array $instructions): void
    {
        $expected = [];
        foreach ($original->postings() as $posting) {
            $key = $posting->account()->id()->toRfc4122().'|'.(-$posting->amountMinor());
            $expected[$key] = ($expected[$key] ?? 0) + 1;
        }

        $actual = [];
        foreach ($instructions as $instruction) {
            $key = $instruction->account->id()->toRfc4122().'|'.$instruction->amountMinor;
            $actual[$key] = ($actual[$key] ?? 0) + 1;
        }

        ksort($expected);
        ksort($actual);
        if ($expected !== $actual) {
            throw new \DomainException('Refund and reversal postings must exactly invert the original transaction.');
        }
    }

    /** @param non-empty-list<WalletPostingInstruction> $instructions */
    private function assertReservationSettlement(WalletReservation $reservation, int $amountMinor, array $instructions): void
    {
        if ($amountMinor <= 0) {
            throw new \InvalidArgumentException('Reservation settlement amount must be positive.');
        }

        $positive = 0;
        $negative = 0;
        $reservedAccountDebit = 0;

        foreach ($instructions as $instruction) {
            if ($instruction->account->currency() !== $reservation->currency()) {
                throw new \DomainException('Reservation settlement currency must match the reservation currency.');
            }
            if ($instruction->amountMinor > 0) {
                $positive += $instruction->amountMinor;
            } else {
                $negative += -$instruction->amountMinor;
            }
            if ($instruction->account === $reservation->account() && $instruction->amountMinor < 0) {
                $reservedAccountDebit += -$instruction->amountMinor;
            }
        }

        if ($positive !== $amountMinor || $negative !== $amountMinor || $reservedAccountDebit !== $amountMinor) {
            throw new \DomainException('Reservation settlement must move exactly the requested amount from the reserved account.');
        }
    }

    /** @return array{0:int,1:int} */
    private function reservationSettlementTotals(WalletReservation $reservation): array
    {
        $row = $this->entityManager->getConnection()->fetchAssociative(
            "SELECT COALESCE(SUM(amount_minor) FILTER (WHERE operation_type = 'capture'), 0) AS captured_minor, COALESCE(SUM(amount_minor) FILTER (WHERE operation_type = 'release'), 0) AS released_minor FROM financial_operation_link WHERE reservation_id = ?",
            [$reservation->id()->toRfc4122()],
        );

        return false === $row ? [0, 0] : [(int) $row['captured_minor'], (int) $row['released_minor']];
    }

    /** @param non-empty-list<WalletPostingInstruction> $instructions */
    private function linkedPosting(WalletTransactionType $type, WalletLedgerTransaction $original, int $amountMinor, string $idempotencyKey, array $instructions): WalletLedgerTransaction
    {
        return $this->entityManager->wrapInTransaction(function () use ($type, $original, $amountMinor, $idempotencyKey, $instructions): WalletLedgerTransaction {
            $this->entityManager->lock($original, LockMode::PESSIMISTIC_WRITE);
            $transaction = $this->postingService->postManaged($type, $idempotencyKey, $instructions, ['original_transaction_id' => $original->id()->toRfc4122(), 'amount_minor' => $amountMinor]);
            $existingLink = $this->entityManager->getRepository(WalletFinancialOperationLink::class)->findOneBy(['resultTransaction' => $transaction]);
            if ($existingLink instanceof WalletFinancialOperationLink) {
                if ($existingLink->sourceTransaction() !== $original || $existingLink->operationType() !== $type || $existingLink->amountMinor() !== $amountMinor) {
                    throw new \DomainException('Idempotent financial operation replay conflicts with the existing operation link.');
                }

                return $transaction;
            }

            $sourceAmount = $this->transactionAmount($original);
            $connection = $this->entityManager->getConnection();
            $refundedMinor = (int) $connection->fetchOne("SELECT COALESCE(SUM(amount_minor), 0) FROM financial_operation_link WHERE source_transaction_id = ? AND operation_type = 'refund'", [$original->id()->toRfc4122()]);
            $hasReverse = (bool) $connection->fetchOne("SELECT EXISTS(SELECT 1 FROM financial_operation_link WHERE source_transaction_id = ? AND operation_type = 'reverse')", [$original->id()->toRfc4122()]);

            if (WalletTransactionType::Refund === $type && ($hasReverse || $refundedMinor + $amountMinor > $sourceAmount)) {
                throw new \DomainException('Refund exceeds the remaining refundable amount or the transaction was reversed.');
            }
            if (WalletTransactionType::Refund === $type) {
                $this->assertRefundLegCapacity($original, $instructions);
            }
            if (WalletTransactionType::Reverse === $type && ($amountMinor !== $sourceAmount || $hasReverse || $refundedMinor > 0)) {
                throw new \DomainException('Reverse requires the full untouched source transaction.');
            }

            $this->entityManager->persist(new WalletFinancialOperationLink($type, $original, $transaction, $amountMinor));
            $this->emitLinkedTransaction($type, $original, $transaction);
            $this->entityManager->flush();

            return $transaction;
        });
    }

    private function emitLinkedTransaction(WalletTransactionType $type, WalletLedgerTransaction $original, WalletLedgerTransaction $transaction): void
    {
        $messageType = 'ledger.transaction.'.$type->value;
        $this->outboxService?->enqueueManaged(
            $messageType,
            $messageType.':'.$transaction->id()->toRfc4122(),
            [
                'transaction_id' => $transaction->id()->toRfc4122(),
                'original_transaction_id' => $original->id()->toRfc4122(),
                'operation' => $type->value,
            ],
            ledgerTransaction: $transaction,
        );
    }

    private function emitReservation(string $messageType, WalletReservation $reservation, WalletLedgerTransaction $transaction): void
    {
        $this->outboxService?->enqueueManaged(
            $messageType,
            $messageType.':'.$reservation->id()->toRfc4122(),
            [
                'reservation_id' => $reservation->id()->toRfc4122(),
                'reservation_key' => $reservation->idempotencyKey(),
                'status' => $reservation->status()->value,
                'amount_minor' => $reservation->amountMinor(),
                'currency' => $reservation->currency(),
                'transaction_id' => $transaction->id()->toRfc4122(),
            ],
            ledgerTransaction: $transaction,
        );
    }

    private function emitFunding(string $messageType, WalletFunding $funding, WalletLedgerTransaction $transaction): void
    {
        $this->outboxService?->enqueueManaged(
            $messageType,
            $messageType.':'.$funding->idempotencyKey(),
            [
                'funding_key' => $funding->idempotencyKey(),
                'status' => $funding->status()->value,
                'amount_minor' => $funding->amountMinor(),
                'currency' => $funding->currency(),
                'transaction_id' => $transaction->id()->toRfc4122(),
            ],
            ledgerTransaction: $transaction,
        );
    }

    private function emitWithdrawal(string $messageType, WalletWithdrawal $withdrawal, WalletLedgerTransaction $transaction): void
    {
        $this->outboxService?->enqueueManaged(
            $messageType,
            $messageType.':'.$withdrawal->idempotencyKey(),
            [
                'withdrawal_key' => $withdrawal->idempotencyKey(),
                'status' => $withdrawal->status()->value,
                'amount_minor' => $withdrawal->amountMinor(),
                'currency' => $withdrawal->currency(),
                'transaction_id' => $transaction->id()->toRfc4122(),
            ],
            ledgerTransaction: $transaction,
        );
    }
}
