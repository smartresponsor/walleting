<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Funding;
use App\Entity\PaymentInstrument;
use App\Entity\ProviderEvent;
use App\Entity\Wallet;
use App\Entity\Withdrawal;
use App\Enum\FundingStatus;
use App\Enum\ProviderEventStatus;
use App\Enum\WithdrawalStatus;
use App\Ledger\PostingInstruction;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final readonly class FundingWithdrawalOrchestrator
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private FinancialOperationService $financialOperations,
        private ProviderEventService $providerEvents,
    ) {
    }

    public function requestFunding(Wallet $wallet, PaymentInstrument $instrument, int $amountMinor, string $currency, string $idempotencyKey): Funding
    {
        return $this->entityManager->wrapInTransaction(function () use ($wallet, $instrument, $amountMinor, $currency, $idempotencyKey): Funding {
            $existing = $this->entityManager->getRepository(Funding::class)->findOneBy(['idempotencyKey' => trim($idempotencyKey)]);
            if ($existing instanceof Funding) {
                if ($existing->wallet() !== $wallet || $existing->paymentInstrument() !== $instrument || $existing->amountMinor() !== $amountMinor || $existing->currency() !== strtoupper(trim($currency))) {
                    throw new \DomainException('Funding idempotency key is already bound to different request content.');
                }

                return $existing;
            }
            $funding = new Funding($wallet, $instrument, $amountMinor, $currency, $idempotencyKey);
            $this->entityManager->persist($funding);
            $this->entityManager->flush();

            return $funding;
        });
    }

    public function requestWithdrawal(Wallet $wallet, PaymentInstrument $instrument, int $amountMinor, string $currency, string $idempotencyKey): Withdrawal
    {
        return $this->entityManager->wrapInTransaction(function () use ($wallet, $instrument, $amountMinor, $currency, $idempotencyKey): Withdrawal {
            $existing = $this->entityManager->getRepository(Withdrawal::class)->findOneBy(['idempotencyKey' => trim($idempotencyKey)]);
            if ($existing instanceof Withdrawal) {
                if ($existing->wallet() !== $wallet || $existing->paymentInstrument() !== $instrument || $existing->amountMinor() !== $amountMinor || $existing->currency() !== strtoupper(trim($currency))) {
                    throw new \DomainException('Withdrawal idempotency key is already bound to different request content.');
                }

                return $existing;
            }
            $withdrawal = new Withdrawal($wallet, $instrument, $amountMinor, $currency, $idempotencyKey);
            $this->entityManager->persist($withdrawal);
            $this->entityManager->flush();

            return $withdrawal;
        });
    }

    public function beginFunding(Funding $funding): ProviderOperationRequest
    {
        return $this->entityManager->wrapInTransaction(function () use ($funding): ProviderOperationRequest {
            $this->entityManager->lock($funding, LockMode::PESSIMISTIC_WRITE);
            if (FundingStatus::Processing === $funding->status()) {
                return $this->requestForFunding($funding);
            }
            $funding->start();
            $this->entityManager->flush();

            return $this->requestForFunding($funding);
        });
    }

    public function beginWithdrawal(Withdrawal $withdrawal): ProviderOperationRequest
    {
        return $this->entityManager->wrapInTransaction(function () use ($withdrawal): ProviderOperationRequest {
            $this->entityManager->lock($withdrawal, LockMode::PESSIMISTIC_WRITE);
            if (WithdrawalStatus::Processing === $withdrawal->status()) {
                return $this->requestForWithdrawal($withdrawal);
            }
            $withdrawal->start();
            $this->entityManager->flush();

            return $this->requestForWithdrawal($withdrawal);
        });
    }

    /** @param non-empty-list<PostingInstruction> $instructions */
    public function succeedFunding(ProviderEvent $event, Funding $funding, string $ledgerIdempotencyKey, array $instructions): void
    {
        $this->entityManager->wrapInTransaction(function () use ($event, $funding, $ledgerIdempotencyKey, $instructions): void {
            $this->assertProvider($event, $funding->paymentInstrument());
            if ($this->isFundingReplay($event, $funding, FundingStatus::Succeeded)) {
                return;
            }
            $this->entityManager->lock($funding, LockMode::PESSIMISTIC_WRITE);
            $this->financialOperations->succeedFunding($funding, $ledgerIdempotencyKey, $instructions);
            $this->providerEvents->processFunding($event, $funding);
        });
    }

    public function failFunding(ProviderEvent $event, Funding $funding): void
    {
        $this->entityManager->wrapInTransaction(function () use ($event, $funding): void {
            $this->assertProvider($event, $funding->paymentInstrument());
            if ($this->isFundingReplay($event, $funding, FundingStatus::Failed)) {
                return;
            }
            $this->entityManager->lock($funding, LockMode::PESSIMISTIC_WRITE);
            $funding->fail();
            $this->providerEvents->processFunding($event, $funding);
            $this->entityManager->flush();
        });
    }

    /** @param non-empty-list<PostingInstruction> $instructions */
    public function succeedWithdrawal(ProviderEvent $event, Withdrawal $withdrawal, string $ledgerIdempotencyKey, array $instructions): void
    {
        $this->entityManager->wrapInTransaction(function () use ($event, $withdrawal, $ledgerIdempotencyKey, $instructions): void {
            $this->assertProvider($event, $withdrawal->paymentInstrument());
            if ($this->isWithdrawalReplay($event, $withdrawal, WithdrawalStatus::Succeeded)) {
                return;
            }
            $this->entityManager->lock($withdrawal, LockMode::PESSIMISTIC_WRITE);
            $this->financialOperations->succeedWithdrawal($withdrawal, $ledgerIdempotencyKey, $instructions);
            $this->providerEvents->processWithdrawal($event, $withdrawal);
        });
    }

    public function failWithdrawal(ProviderEvent $event, Withdrawal $withdrawal): void
    {
        $this->entityManager->wrapInTransaction(function () use ($event, $withdrawal): void {
            $this->assertProvider($event, $withdrawal->paymentInstrument());
            if ($this->isWithdrawalReplay($event, $withdrawal, WithdrawalStatus::Failed)) {
                return;
            }
            $this->entityManager->lock($withdrawal, LockMode::PESSIMISTIC_WRITE);
            $withdrawal->fail();
            $this->providerEvents->processWithdrawal($event, $withdrawal);
            $this->entityManager->flush();
        });
    }

    private function isFundingReplay(ProviderEvent $event, Funding $funding, FundingStatus $expectedStatus): bool
    {
        if (ProviderEventStatus::Processed !== $event->status()) {
            return false;
        }
        if ($event->funding() === $funding && $funding->status() === $expectedStatus) {
            return true;
        }

        throw new \DomainException('Processed provider event conflicts with the funding operation or outcome.');
    }

    private function isWithdrawalReplay(ProviderEvent $event, Withdrawal $withdrawal, WithdrawalStatus $expectedStatus): bool
    {
        if (ProviderEventStatus::Processed !== $event->status()) {
            return false;
        }
        if ($event->withdrawal() === $withdrawal && $withdrawal->status() === $expectedStatus) {
            return true;
        }

        throw new \DomainException('Processed provider event conflicts with the withdrawal operation or outcome.');
    }

    private function requestForFunding(Funding $funding): ProviderOperationRequest
    {
        $instrument = $funding->paymentInstrument();

        return new ProviderOperationRequest('funding', $funding->id()->toRfc4122(), $funding->idempotencyKey(), $instrument->provider(), $instrument->providerReference(), $funding->amountMinor(), $funding->currency());
    }

    private function requestForWithdrawal(Withdrawal $withdrawal): ProviderOperationRequest
    {
        $instrument = $withdrawal->paymentInstrument();

        return new ProviderOperationRequest('withdrawal', $withdrawal->id()->toRfc4122(), $withdrawal->idempotencyKey(), $instrument->provider(), $instrument->providerReference(), $withdrawal->amountMinor(), $withdrawal->currency());
    }

    private function assertProvider(ProviderEvent $event, PaymentInstrument $instrument): void
    {
        if ($event->provider() !== $instrument->provider()) {
            throw new \DomainException('Provider event does not match the operation payment instrument provider.');
        }
    }
}
