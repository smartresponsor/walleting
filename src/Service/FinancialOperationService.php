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
    public function __construct(private EntityManagerInterface $entityManager, private PostingService $postingService)
    {
    }

    /** @param non-empty-list<PostingInstruction> $instructions */
    public function reserve(Wallet $wallet, Account $account, int $amountMinor, string $currency, string $idempotencyKey, array $instructions, ?\DateTimeImmutable $expiresAt = null): Reservation
    {
        return $this->entityManager->wrapInTransaction(function () use ($wallet, $account, $amountMinor, $currency, $idempotencyKey, $instructions, $expiresAt): Reservation {
            $transaction = $this->postingService->postManaged(TransactionType::Reserve, $idempotencyKey, $instructions, ['operation' => 'reserve']);
            $reservation = new Reservation($wallet, $account, $transaction, $amountMinor, $currency, $idempotencyKey, $expiresAt);
            $this->entityManager->persist($reservation);
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
        return $this->linkedPosting(TransactionType::Refund, $original, $idempotencyKey, $instructions);
    }

    /** @param non-empty-list<PostingInstruction> $instructions */
    public function reverse(LedgerTransaction $original, string $idempotencyKey, array $instructions): LedgerTransaction
    {
        return $this->linkedPosting(TransactionType::Reverse, $original, $idempotencyKey, $instructions);
    }

    /** @param non-empty-list<PostingInstruction> $instructions */
    public function succeedFunding(Funding $funding, string $idempotencyKey, array $instructions): LedgerTransaction
    {
        return $this->entityManager->wrapInTransaction(function () use ($funding, $idempotencyKey, $instructions): LedgerTransaction {
            $transaction = $this->postingService->postManaged(TransactionType::Credit, $idempotencyKey, $instructions, ['operation' => 'funding', 'funding_key' => $funding->idempotencyKey()]);
            $funding->succeed($transaction);
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

        return $this->entityManager->wrapInTransaction(function () use ($funding, $original, $idempotencyKey, $instructions): LedgerTransaction {
            $transaction = $this->postingService->postManaged(TransactionType::Reverse, $idempotencyKey, $instructions, ['operation' => 'funding_reversal', 'funding_key' => $funding->idempotencyKey(), 'original_transaction_id' => $original->id()->toRfc4122()]);
            $this->entityManager->persist(new FinancialOperationLink(TransactionType::Reverse, $original, $transaction));
            $funding->reverse($transaction);
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

        return $this->entityManager->wrapInTransaction(function () use ($withdrawal, $original, $idempotencyKey, $instructions): LedgerTransaction {
            $transaction = $this->postingService->postManaged(TransactionType::Reverse, $idempotencyKey, $instructions, ['operation' => 'withdrawal_reversal', 'withdrawal_key' => $withdrawal->idempotencyKey(), 'original_transaction_id' => $original->id()->toRfc4122()]);
            $this->entityManager->persist(new FinancialOperationLink(TransactionType::Reverse, $original, $transaction));
            $withdrawal->reverse($transaction);
            $this->entityManager->flush();

            return $transaction;
        });
    }

    private function transitionReservation(Reservation $reservation, string $idempotencyKey, array $instructions, TransactionType $type, string $operation): LedgerTransaction
    {
        return $this->entityManager->wrapInTransaction(function () use ($reservation, $idempotencyKey, $instructions, $type, $operation): LedgerTransaction {
            $transaction = $this->postingService->postManaged($type, $idempotencyKey, $instructions, ['operation' => $operation, 'reservation_key' => $reservation->idempotencyKey()]);
            $this->entityManager->persist(new FinancialOperationLink($type, $reservation->reserveTransaction(), $transaction, $reservation));
            'capture' === $operation ? $reservation->capture() : $reservation->release();
            $this->entityManager->flush();

            return $transaction;
        });
    }

    private function linkedPosting(TransactionType $type, LedgerTransaction $original, string $idempotencyKey, array $instructions): LedgerTransaction
    {
        return $this->entityManager->wrapInTransaction(function () use ($type, $original, $idempotencyKey, $instructions): LedgerTransaction {
            $transaction = $this->postingService->postManaged($type, $idempotencyKey, $instructions, ['original_transaction_id' => $original->id()->toRfc4122()]);
            $this->entityManager->persist(new FinancialOperationLink($type, $original, $transaction));
            $this->entityManager->flush();

            return $transaction;
        });
    }
}
