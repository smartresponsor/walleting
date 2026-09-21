<?php

declare(strict_types=1);

namespace App\Walleting\Service;

use App\Walleting\Entity\WalletLedgerTransaction;
use App\Walleting\Enum\WalletTransactionStatus;
use App\Walleting\Enum\WalletTransactionType;
use App\Walleting\ValueObject\Ledger\WalletFinancialPostingRequest;
use App\Walleting\ValueObject\Ledger\WalletPostingInstruction;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class WalletPostingService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private WalletOutboxService $outboxService,
        private WalletPostingExecutorInterface $postingExecutor,
    ) {
    }

    /** @param non-empty-list<WalletPostingInstruction> $instructions */
    public function post(
        WalletTransactionType $type,
        string $idempotencyKey,
        array $instructions,
        array $metadata = [],
    ): WalletLedgerTransaction {
        $idempotencyKey = trim($idempotencyKey);
        $this->validate($idempotencyKey, $instructions);
        $request = new WalletFinancialPostingRequest($type, $instructions, $metadata);
        $requestHash = $request->hash();

        $existing = $this->findExisting($idempotencyKey);
        if ($existing instanceof WalletLedgerTransaction) {
            $this->assertSameRequest($existing, $requestHash);

            return $existing;
        }

        try {
            $transactionId = $this->postingExecutor->execute($idempotencyKey, $request);

            $transaction = $this->entityManager->find(WalletLedgerTransaction::class, Uuid::fromString($transactionId));
            if (!$transaction instanceof WalletLedgerTransaction) {
                throw new \RuntimeException('Posted ledger transaction could not be hydrated after commit.');
            }

            return $transaction;
        } catch (UniqueConstraintViolationException $exception) {
            $existing = $this->findExisting($idempotencyKey);
            if ($existing instanceof WalletLedgerTransaction && WalletTransactionStatus::Posted === $existing->status()) {
                $this->assertSameRequest($existing, $requestHash);

                return $existing;
            }

            throw $exception;
        }
    }

    /** @param non-empty-list<WalletPostingInstruction> $instructions */
    public function postManaged(WalletTransactionType $type, string $idempotencyKey, array $instructions, array $metadata = []): WalletLedgerTransaction
    {
        $idempotencyKey = trim($idempotencyKey);
        $this->validate($idempotencyKey, $instructions);
        $request = new WalletFinancialPostingRequest($type, $instructions, $metadata);
        $requestHash = $request->hash();

        $existing = $this->findExisting($idempotencyKey);
        if ($existing instanceof WalletLedgerTransaction) {
            $this->assertSameRequest($existing, $requestHash);

            return $existing;
        }

        $transaction = new WalletLedgerTransaction($type, $idempotencyKey, $metadata, requestHash: $requestHash);
        foreach ($instructions as $instruction) {
            $transaction->addPosting($instruction->account, $instruction->amountMinor);
        }
        $transaction->post();
        $this->entityManager->persist($transaction);
        $this->emitPosted($transaction);

        return $transaction;
    }

    /** @param non-empty-list<WalletPostingInstruction> $instructions */
    public function debit(string $idempotencyKey, array $instructions, array $metadata = []): WalletLedgerTransaction
    {
        return $this->post(WalletTransactionType::Debit, $idempotencyKey, $instructions, $metadata);
    }

    /** @param non-empty-list<WalletPostingInstruction> $instructions */
    public function credit(string $idempotencyKey, array $instructions, array $metadata = []): WalletLedgerTransaction
    {
        return $this->post(WalletTransactionType::Credit, $idempotencyKey, $instructions, $metadata);
    }

    /** @param non-empty-list<WalletPostingInstruction> $instructions */
    public function transfer(string $idempotencyKey, array $instructions, array $metadata = []): WalletLedgerTransaction
    {
        return $this->post(WalletTransactionType::Transfer, $idempotencyKey, $instructions, $metadata);
    }

    /** @param list<WalletPostingInstruction> $instructions */
    private function validate(string $idempotencyKey, array $instructions): void
    {
        if ('' === $idempotencyKey || count($instructions) < 2) {
            throw new \InvalidArgumentException('Idempotency key and at least two posting instructions are required.');
        }

        $currency = null;
        $balance = 0;
        foreach ($instructions as $instruction) {
            $currency ??= $instruction->account->currency();
            if ($currency !== $instruction->account->currency()) {
                throw new \InvalidArgumentException('One ledger transaction cannot contain multiple currencies.');
            }
            $balance += $instruction->amountMinor;
        }

        if (0 !== $balance) {
            throw new \InvalidArgumentException('Posting instructions must balance to zero.');
        }
    }

    private function findExisting(string $idempotencyKey): ?WalletLedgerTransaction
    {
        return $this->entityManager->getRepository(WalletLedgerTransaction::class)->findOneBy(['idempotencyKey' => $idempotencyKey]);
    }

    private function assertSameRequest(WalletLedgerTransaction $existing, string $requestHash): void
    {
        if (!hash_equals($existing->requestHash(), $requestHash)) {
            throw new \DomainException('Idempotency key is already bound to a different financial request.');
        }
    }

    private function emitPosted(WalletLedgerTransaction $transaction): void
    {
        $this->outboxService->enqueueManaged(
            'ledger.transaction.posted',
            'ledger.transaction.posted:'.$transaction->id()->toRfc4122(),
            [
                'transaction_id' => $transaction->id()->toRfc4122(),
                'transaction_type' => $transaction->type()->value,
                'idempotency_key' => $transaction->idempotencyKey(),
                'request_hash' => $transaction->requestHash(),
                'metadata' => $transaction->metadata(),
            ],
            ledgerTransaction: $transaction,
        );
    }
}
