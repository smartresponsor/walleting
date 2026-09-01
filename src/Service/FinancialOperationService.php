<?php

declare(strict_types=1);

namespace App\Walleting\Service;

use App\Walleting\Entity\Account;
use App\Walleting\Entity\FinancialOperationLink;
use App\Walleting\Entity\Funding;
use App\Walleting\Entity\LedgerTransaction;
use App\Walleting\Entity\Reservation;
use App\Walleting\Entity\Wallet;
use App\Walleting\Entity\Withdrawal;
use App\Walleting\Enum\FundingStatus;
use App\Walleting\Enum\TransactionType;
use App\Walleting\Enum\WithdrawalStatus;
use App\Walleting\Ledger\FeeAllocation;
use App\Walleting\Ledger\PostingInstruction;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final readonly class FinancialOperationService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PostingService $postingService,
        private ?OutboxService $outboxService = null,
        private ?FeePostingComposer $feePostingComposer = null,
    ) {
    }

    /** @param non-empty-list<PostingInstruction> $instructions */
    public function reserve(Wallet $wallet, Account $account, int $amountMinor, string $currency, string $idempotencyKey, array $instructions, ?\DateTimeImmutable $expiresAt = null): Reservation
    {
        return $this->entityManager->wrapInTransaction(function () use ($wallet, $account, $amountMinor, $currency, $idempotencyKey, $instructions, $expiresAt): Reservation {
            $transaction = $this->postingService->postManaged(TransactionType::Reserve, $idempotencyKey, $instructions, ['operation' => 'reserve']);
            $existing = $this->entityManager->getRepository(Reservation::class)->findOneBy(['idempotencyKey' => trim($idempotencyKey)]);
            if ($existing instanceof Reservation) {
                if ($existing->wallet() !== $wallet || $existing->account() !== $account || $existing->reserveTransaction() !== $transaction || $existing->amountMinor() !== $amountMinor || $existing->currency() !== strtoupper(trim($currency))) {
                    throw new \DomainException('Idempotent reservation replay conflicts with the existing reservation.');
                }

                return $existing;
            }

            $reservation = new Reservation($wallet, $account, $transaction, $amountMinor, $currency, $idempotencyKey, $expiresAt);
            $this->entityManager->persist($reservation);
            $this->emitReservation('wallet.reservation.created', $reservation, $transaction);
            $this->entityManager->flush();

            return $reservation;
        });
    }

    /** @param non-empty-list<PostingInstruction> $instructions */
    public function capture(Reservation $reservation, string $idempotencyKey, array $instructions): LedgerTransaction
    {
        return $this->capturePartial($reservation, $reservation->amountMinor(), $idempotencyKey, $instructions);
    }

    /** @param non-empty-list<PostingInstruction> $instructions */
    public function capturePartial(Reservation $reservation, int $amountMinor, string $idempotencyKey, array $instructions): LedgerTransaction
    {
        return $this->transitionReservation($reservation, $amountMinor, $idempotencyKey, $instructions, TransactionType::Capture, 'capture');
    }

    /** @param list<FeeAllocation> $fees */
    public function captureWithFees(Reservation $reservation, Account $netDestination, array $fees, string $idempotencyKey): LedgerTransaction
    {
        return $this->capturePartialWithFees($reservation, $reservation->amountMinor(), $netDestination, $fees, $idempotencyKey);
    }

    /** @param list<FeeAllocation> $fees */
    public function capturePartialWithFees(Reservation $reservation, int $amountMinor, Account $netDestination, array $fees, string $idempotencyKey): LedgerTransaction
    {
        $plan = ($this->feePostingComposer ?? new FeePostingComposer())->compose($reservation->account(), $netDestination, $amountMinor, $fees);

        return $this->transitionReservation(
            $reservation,
            $amountMinor,
            $idempotencyKey,
            $plan->instructions,
            TransactionType::Capture,
            'capture',
            ['settlement' => $plan->metadata],
        );
    }

    /** @param non-empty-list<PostingInstruction> $instructions */
    public function release(Reservation $reservation, string $idempotencyKey, array $instructions): LedgerTransaction
    {
        return $this->releasePartial($reservation, $reservation->amountMinor(), $idempotencyKey, $instructions);
    }

    /** @param non-empty-list<PostingInstruction> $instructions */
    public function releasePartial(Reservation $reservation, int $amountMinor, string $idempotencyKey, array $instructions): LedgerTransaction
    {
        return $this->transitionReservation($reservation, $amountMinor, $idempotencyKey, $instructions, TransactionType::Release, 'release');
    }

    /** @param non-empty-list<PostingInstruction> $instructions */
    public function refund(LedgerTransaction $original, string $idempotencyKey, array $instructions): LedgerTransaction
    {
        $this->assertInverseSourceAllowed($original);
        $this->assertExactInverse($original, $instructions);

        return $this->linkedPosting(TransactionType::Refund, $original, $this->transactionAmount($original), $idempotencyKey, $instructions);
    }

    /** @param non-empty-list<PostingInstruction> $instructions */
    public function refundPartial(LedgerTransaction $original, int $amountMinor, string $idempotencyKey, array $instructions): LedgerTransaction
    {
        $this->assertInverseSourceAllowed($original);
        $this->assertPartialInverse($original, $amountMinor, $instructions);

        return $this->linkedPosting(TransactionType::Refund, $original, $amountMinor, $idempotencyKey, $instructions);
    }

    /** @param non-empty-list<PostingInstruction> $instructions */
    public function refundPartialAllocated(LedgerTransaction $original, int $amountMinor, string $idempotencyKey, array $instructions): LedgerTransaction
    {
        $this->assertInverseSourceAllowed($original);
        $this->assertAllocatedPartialInverse($original, $amountMinor, $instructions);

        return $this->linkedPosting(TransactionType::Refund, $original, $amountMinor, $idempotencyKey, $instructions);
    }

    /** @param non-empty-list<PostingInstruction> $instructions */
    public function reverse(LedgerTransaction $original, string $idempotencyKey, array $instructions): LedgerTransaction
    {
        $this->assertInverseSourceAllowed($original);
        $this->assertExactInverse($original, $instructions);

        return $this->linkedPosting(TransactionType::Reverse, $original, $this->transactionAmount($original), $idempotencyKey, $instructions);
    }

    /** @param non-empty-list<PostingInstruction> $instructions */
    public function succeedFunding(Funding $funding, string $idempotencyKey, array $instructions): LedgerTransaction
    {
        return $this->entityManager->wrapInTransaction(function () use ($funding, $idempotencyKey, $instructions): LedgerTransaction {
            $this->entityManager->lock($funding, LockMode::PESSIMISTIC_WRITE);
            $transaction = $this->postingService->postManaged(TransactionType::Credit, $idempotencyKey, $instructions, ['operation' => 'funding', 'funding_key' => $funding->idempotencyKey()]);
            $existing = $funding->transaction();
            if ($existing instanceof LedgerTransaction) {
                if ($existing !== $transaction || !in_array($funding->status(), [FundingStatus::Succeeded, FundingStatus::Reversed], true)) {
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

    /** @param non-empty-list<PostingInstruction> $instructions */
    public function succeedWithdrawal(Withdrawal $withdrawal, string $idempotencyKey, array $instructions): LedgerTransaction
    {
        return $this->entityManager->wrapInTransaction(function () use ($withdrawal, $idempotencyKey, $instructions): LedgerTransaction {
            $this->entityManager->lock($withdrawal, LockMode::PESSIMISTIC_WRITE);
            $transaction = $this->postingService->postManaged(TransactionType::Debit, $idempotencyKey, $instructions, ['operation' => 'withdrawal', 'withdrawal_key' => $withdrawal->idempotencyKey()]);
            $existing = $withdrawal->transaction();
            if ($existing instanceof LedgerTransaction) {
                if ($existing !== $transaction || !in_array($withdrawal->status(), [WithdrawalStatus::Succeeded, WithdrawalStatus::Reversed], true)) {
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

    /** @param non-empty-list<PostingInstruction> $instructions */
    public function reverseFunding(Funding $funding, string $idempotencyKey, array $instructions): LedgerTransaction
    {
        return $this->entityManager->wrapInTransaction(function () use ($funding, $idempotencyKey, $instructions): LedgerTransaction {
            $this->entityManager->lock($funding, LockMode::PESSIMISTIC_WRITE);
            $original = $funding->transaction();
            if (!$original instanceof LedgerTransaction) {
                throw new \LogicException('Funding must have a successful ledger transaction before reversal.');
            }
            $this->assertExactInverse($original, $instructions);

            if (FundingStatus::Reversed === $funding->status()) {
                $existing = $funding->reversalTransaction();
                if ($existing instanceof LedgerTransaction && $existing->idempotencyKey() === trim($idempotencyKey)) {
                    return $existing;
                }

                throw new \DomainException('Funding reversal already exists with a different idempotency key.');
            }

            $transaction = $this->postingService->postManaged(TransactionType::Reverse, $idempotencyKey, $instructions, ['operation' => 'funding_reversal', 'funding_key' => $funding->idempotencyKey(), 'original_transaction_id' => $original->id()->toRfc4122()]);
            $this->entityManager->persist(new FinancialOperationLink(TransactionType::Reverse, $original, $transaction, $this->transactionAmount($original)));
            $funding->reverse($transaction);
            $this->emitFunding('wallet.funding.reversed', $funding, $transaction);
            $this->entityManager->flush();

            return $transaction;
        });
    }

    /** @param non-empty-list<PostingInstruction> $instructions */
    public function reverseWithdrawal(Withdrawal $withdrawal, string $idempotencyKey, array $instructions): LedgerTransaction
    {
        return $this->entityManager->wrapInTransaction(function () use ($withdrawal, $idempotencyKey, $instructions): LedgerTransaction {
            $this->entityManager->lock($withdrawal, LockMode::PESSIMISTIC_WRITE);
            $original = $withdrawal->transaction();
            if (!$original instanceof LedgerTransaction) {
                throw new \LogicException('Withdrawal must have a successful ledger transaction before reversal.');
            }
            $this->assertExactInverse($original, $instructions);

            if (WithdrawalStatus::Reversed === $withdrawal->status()) {
                $existing = $withdrawal->reversalTransaction();
                if ($existing instanceof LedgerTransaction && $existing->idempotencyKey() === trim($idempotencyKey)) {
                    return $existing;
                }

                throw new \DomainException('Withdrawal reversal already exists with a different idempotency key.');
            }

            $transaction = $this->postingService->postManaged(TransactionType::Reverse, $idempotencyKey, $instructions, ['operation' => 'withdrawal_reversal', 'withdrawal_key' => $withdrawal->idempotencyKey(), 'original_transaction_id' => $original->id()->toRfc4122()]);
            $this->entityManager->persist(new FinancialOperationLink(TransactionType::Reverse, $original, $transaction, $this->transactionAmount($original)));
            $withdrawal->reverse($transaction);
            $this->emitWithdrawal('wallet.withdrawal.reversed', $withdrawal, $transaction);
            $this->entityManager->flush();

            return $transaction;
        });
    }

    /** @param non-empty-list<PostingInstruction> $instructions */
    private function transitionReservation(Reservation $reservation, int $amountMinor, string $idempotencyKey, array $instructions, TransactionType $type, string $operation, array $metadata = []): LedgerTransaction
    {
        $this->assertReservationSettlement($reservation, $amountMinor, $instructions);

        return $this->entityManager->wrapInTransaction(function () use ($reservation, $amountMinor, $idempotencyKey, $instructions, $type, $operation, $metadata): LedgerTransaction {
            $this->entityManager->lock($reservation, LockMode::PESSIMISTIC_WRITE);
            $transaction = $this->postingService->postManaged($type, $idempotencyKey, $instructions, array_replace_recursive(['operation' => $operation, 'reservation_key' => $reservation->idempotencyKey(), 'amount_minor' => $amountMinor], $metadata));
            $existingLink = $this->entityManager->getRepository(FinancialOperationLink::class)->findOneBy(['resultTransaction' => $transaction]);
            if ($existingLink instanceof FinancialOperationLink) {
                if ($existingLink->reservation() !== $reservation || $existingLink->sourceTransaction() !== $reservation->reserveTransaction() || $existingLink->operationType() !== $type || $existingLink->amountMinor() !== $amountMinor) {
                    throw new \DomainException('Idempotent reservation settlement replay conflicts with the existing operation link.');
                }

                return $transaction;
            }

            [$capturedMinor, $releasedMinor] = $this->reservationSettlementTotals($reservation);
            if ($capturedMinor + $releasedMinor + $amountMinor > $reservation->amountMinor()) {
                throw new \DomainException('Reservation settlement exceeds the remaining reserved amount.');
            }

            $this->entityManager->persist(new FinancialOperationLink($type, $reservation->reserveTransaction(), $transaction, $amountMinor, $reservation));
            'capture' === $operation ? $capturedMinor += $amountMinor : $releasedMinor += $amountMinor;
            $reservation->recordSettlementProgress($capturedMinor, $releasedMinor);
            $this->emitReservation('wallet.reservation.'.$operation.'d', $reservation, $transaction);
            $this->entityManager->flush();

            return $transaction;
        });
    }

    private function assertInverseSourceAllowed(LedgerTransaction $original): void
    {
        if (in_array($original->type(), [TransactionType::Refund, TransactionType::Reverse], true)) {
            throw new \DomainException('Refund and reverse cannot originate from an inverse transaction.');
        }
    }

    /** @param non-empty-list<PostingInstruction> $instructions */
    private function assertAllocatedPartialInverse(LedgerTransaction $original, int $amountMinor, array $instructions): void
    {
        if ($amountMinor <= 0) {
            throw new \InvalidArgumentException('Partial refund amount must be positive.');
        }

        $source = [];
        foreach ($original->postings() as $posting) {
            $accountId = $posting->account()->id()->toRfc4122();
            $source[$accountId] = ($source[$accountId] ?? 0) + $posting->amountMinor();
        }
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
        if ($positive !== $amountMinor || 0 !== array_sum($actual)) {
            throw new \DomainException('Allocated refund postings must balance to the requested refund amount.');
        }
        foreach ($actual as $accountId => $amount) {
            $sourceAmount = $source[$accountId];
            if (0 === $amount || 0 === $sourceAmount || ($sourceAmount > 0 && ($amount > 0 || -$amount > $sourceAmount)) || ($sourceAmount < 0 && ($amount < 0 || $amount > -$sourceAmount))) {
                throw new \DomainException('Allocated refund must invert original account legs without exceeding them.');
            }
        }
    }

    /** @param non-empty-list<PostingInstruction> $instructions */
    private function assertPartialInverse(LedgerTransaction $original, int $amountMinor, array $instructions): void
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

    /** @param non-empty-list<PostingInstruction> $instructions */
    private function assertRefundLegCapacity(LedgerTransaction $original, array $instructions): void
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

    private function transactionAmount(LedgerTransaction $transaction): int
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

    /** @param non-empty-list<PostingInstruction> $instructions */
    private function assertExactInverse(LedgerTransaction $original, array $instructions): void
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

    /** @param non-empty-list<PostingInstruction> $instructions */
    private function assertReservationSettlement(Reservation $reservation, int $amountMinor, array $instructions): void
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
    private function reservationSettlementTotals(Reservation $reservation): array
    {
        $row = $this->entityManager->getConnection()->fetchAssociative(
            "SELECT COALESCE(SUM(amount_minor) FILTER (WHERE operation_type = 'capture'), 0) AS captured_minor, COALESCE(SUM(amount_minor) FILTER (WHERE operation_type = 'release'), 0) AS released_minor FROM financial_operation_link WHERE reservation_id = ?",
            [$reservation->id()->toRfc4122()],
        );

        return false === $row ? [0, 0] : [(int) $row['captured_minor'], (int) $row['released_minor']];
    }

    /** @param non-empty-list<PostingInstruction> $instructions */
    private function linkedPosting(TransactionType $type, LedgerTransaction $original, int $amountMinor, string $idempotencyKey, array $instructions): LedgerTransaction
    {
        return $this->entityManager->wrapInTransaction(function () use ($type, $original, $amountMinor, $idempotencyKey, $instructions): LedgerTransaction {
            $this->entityManager->lock($original, LockMode::PESSIMISTIC_WRITE);
            $transaction = $this->postingService->postManaged($type, $idempotencyKey, $instructions, ['original_transaction_id' => $original->id()->toRfc4122(), 'amount_minor' => $amountMinor]);
            $existingLink = $this->entityManager->getRepository(FinancialOperationLink::class)->findOneBy(['resultTransaction' => $transaction]);
            if ($existingLink instanceof FinancialOperationLink) {
                if ($existingLink->sourceTransaction() !== $original || $existingLink->operationType() !== $type || $existingLink->amountMinor() !== $amountMinor) {
                    throw new \DomainException('Idempotent financial operation replay conflicts with the existing operation link.');
                }

                return $transaction;
            }

            $sourceAmount = $this->transactionAmount($original);
            $connection = $this->entityManager->getConnection();
            $refundedMinor = (int) $connection->fetchOne("SELECT COALESCE(SUM(amount_minor), 0) FROM financial_operation_link WHERE source_transaction_id = ? AND operation_type = 'refund'", [$original->id()->toRfc4122()]);
            $hasReverse = (bool) $connection->fetchOne("SELECT EXISTS(SELECT 1 FROM financial_operation_link WHERE source_transaction_id = ? AND operation_type = 'reverse')", [$original->id()->toRfc4122()]);

            if (TransactionType::Refund === $type && ($hasReverse || $refundedMinor + $amountMinor > $sourceAmount)) {
                throw new \DomainException('Refund exceeds the remaining refundable amount or the transaction was reversed.');
            }
            if (TransactionType::Refund === $type) {
                $this->assertRefundLegCapacity($original, $instructions);
            }
            if (TransactionType::Reverse === $type && ($amountMinor !== $sourceAmount || $hasReverse || $refundedMinor > 0)) {
                throw new \DomainException('Reverse requires the full untouched source transaction.');
            }

            $this->entityManager->persist(new FinancialOperationLink($type, $original, $transaction, $amountMinor));
            $this->emitLinkedTransaction($type, $original, $transaction);
            $this->entityManager->flush();

            return $transaction;
        });
    }

    private function emitLinkedTransaction(TransactionType $type, LedgerTransaction $original, LedgerTransaction $transaction): void
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

    private function emitReservation(string $messageType, Reservation $reservation, LedgerTransaction $transaction): void
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

    private function emitFunding(string $messageType, Funding $funding, LedgerTransaction $transaction): void
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

    private function emitWithdrawal(string $messageType, Withdrawal $withdrawal, LedgerTransaction $transaction): void
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
