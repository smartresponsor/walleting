<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Account;
use App\Entity\FinancialOperationLink;
use App\Entity\Funding;
use App\Entity\LedgerTransaction;
use App\Entity\Reservation;
use App\Entity\Wallet;
use App\Entity\Withdrawal;
use App\Enum\TransactionType;
use App\Ledger\PostingInstruction;
use Doctrine\ORM\EntityManagerInterface;

final readonly class FinancialOperationService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PostingService $postingService,
        private ?OutboxService $outboxService = null,
    ) {
    }

    /** @param non-empty-list<PostingInstruction> $instructions */
    public function reserve(Wallet $wallet, Account $account, int $amountMinor, string $currency, string $idempotencyKey, array $instructions, ?\DateTimeImmutable $expiresAt = null): Reservation
    {
        return $this->entityManager->wrapInTransaction(function () use ($wallet, $account, $amountMinor, $currency, $idempotencyKey, $instructions, $expiresAt): Reservation {
            $transaction = $this->postingService->postManaged(TransactionType::Reserve, $idempotencyKey, $instructions, ['operation' => 'reserve']);
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
        return $this->transitionReservation($reservation, $idempotencyKey, $instructions, TransactionType::Capture, 'capture');
    }

    /** @param non-empty-list<PostingInstruction> $instructions */
    public function release(Reservation $reservation, string $idempotencyKey, array $instructions): LedgerTransaction
    {
        return $this->transitionReservation($reservation, $idempotencyKey, $instructions, TransactionType::Release, 'release');
    }

    /** @param non-empty-list<PostingInstruction> $instructions */
    public function refund(LedgerTransaction $original, string $idempotencyKey, array $instructions): LedgerTransaction
    {
        $this->assertInverseSourceAllowed($original);
        $this->assertExactInverse($original, $instructions);

        return $this->linkedPosting(TransactionType::Refund, $original, $idempotencyKey, $instructions);
    }

    /** @param non-empty-list<PostingInstruction> $instructions */
    public function reverse(LedgerTransaction $original, string $idempotencyKey, array $instructions): LedgerTransaction
    {
        $this->assertInverseSourceAllowed($original);
        $this->assertExactInverse($original, $instructions);

        return $this->linkedPosting(TransactionType::Reverse, $original, $idempotencyKey, $instructions);
    }

    /** @param non-empty-list<PostingInstruction> $instructions */
    public function succeedFunding(Funding $funding, string $idempotencyKey, array $instructions): LedgerTransaction
    {
        return $this->entityManager->wrapInTransaction(function () use ($funding, $idempotencyKey, $instructions): LedgerTransaction {
            $transaction = $this->postingService->postManaged(TransactionType::Credit, $idempotencyKey, $instructions, ['operation' => 'funding', 'funding_key' => $funding->idempotencyKey()]);
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
            $withdrawal->start();
            $transaction = $this->postingService->postManaged(TransactionType::Debit, $idempotencyKey, $instructions, ['operation' => 'withdrawal', 'withdrawal_key' => $withdrawal->idempotencyKey()]);
            $withdrawal->succeed($transaction);
            $this->emitWithdrawal('wallet.withdrawal.succeeded', $withdrawal, $transaction);
            $this->entityManager->flush();

            return $transaction;
        });
    }

    /** @param non-empty-list<PostingInstruction> $instructions */
    public function reverseFunding(Funding $funding, string $idempotencyKey, array $instructions): LedgerTransaction
    {
        $original = $funding->transaction();
        if (!$original instanceof LedgerTransaction) {
            throw new \LogicException('Funding must have a successful ledger transaction before reversal.');
        }
        $this->assertExactInverse($original, $instructions);

        return $this->entityManager->wrapInTransaction(function () use ($funding, $original, $idempotencyKey, $instructions): LedgerTransaction {
            $transaction = $this->postingService->postManaged(TransactionType::Reverse, $idempotencyKey, $instructions, ['operation' => 'funding_reversal', 'funding_key' => $funding->idempotencyKey(), 'original_transaction_id' => $original->id()->toRfc4122()]);
            $this->entityManager->persist(new FinancialOperationLink(TransactionType::Reverse, $original, $transaction));
            $funding->reverse($transaction);
            $this->emitFunding('wallet.funding.reversed', $funding, $transaction);
            $this->entityManager->flush();

            return $transaction;
        });
    }

    /** @param non-empty-list<PostingInstruction> $instructions */
    public function reverseWithdrawal(Withdrawal $withdrawal, string $idempotencyKey, array $instructions): LedgerTransaction
    {
        $original = $withdrawal->transaction();
        if (!$original instanceof LedgerTransaction) {
            throw new \LogicException('Withdrawal must have a successful ledger transaction before reversal.');
        }
        $this->assertExactInverse($original, $instructions);

        return $this->entityManager->wrapInTransaction(function () use ($withdrawal, $original, $idempotencyKey, $instructions): LedgerTransaction {
            $transaction = $this->postingService->postManaged(TransactionType::Reverse, $idempotencyKey, $instructions, ['operation' => 'withdrawal_reversal', 'withdrawal_key' => $withdrawal->idempotencyKey(), 'original_transaction_id' => $original->id()->toRfc4122()]);
            $this->entityManager->persist(new FinancialOperationLink(TransactionType::Reverse, $original, $transaction));
            $withdrawal->reverse($transaction);
            $this->emitWithdrawal('wallet.withdrawal.reversed', $withdrawal, $transaction);
            $this->entityManager->flush();

            return $transaction;
        });
    }

    private function transitionReservation(Reservation $reservation, string $idempotencyKey, array $instructions, TransactionType $type, string $operation): LedgerTransaction
    {
        $this->assertReservationSettlement($reservation, $instructions);

        return $this->entityManager->wrapInTransaction(function () use ($reservation, $idempotencyKey, $instructions, $type, $operation): LedgerTransaction {
            $transaction = $this->postingService->postManaged($type, $idempotencyKey, $instructions, ['operation' => $operation, 'reservation_key' => $reservation->idempotencyKey()]);
            $this->entityManager->persist(new FinancialOperationLink($type, $reservation->reserveTransaction(), $transaction, $reservation));
            'capture' === $operation ? $reservation->capture() : $reservation->release();
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
    private function assertReservationSettlement(Reservation $reservation, array $instructions): void
    {
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

        if ($positive !== $reservation->amountMinor() || $negative !== $reservation->amountMinor() || $reservedAccountDebit !== $reservation->amountMinor()) {
            throw new \DomainException('Reservation settlement must move exactly the reserved amount from the reserved account.');
        }
    }

    private function linkedPosting(TransactionType $type, LedgerTransaction $original, string $idempotencyKey, array $instructions): LedgerTransaction
    {
        return $this->entityManager->wrapInTransaction(function () use ($type, $original, $idempotencyKey, $instructions): LedgerTransaction {
            $transaction = $this->postingService->postManaged($type, $idempotencyKey, $instructions, ['original_transaction_id' => $original->id()->toRfc4122()]);
            $this->entityManager->persist(new FinancialOperationLink($type, $original, $transaction));
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
