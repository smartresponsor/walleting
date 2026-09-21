<?php

declare(strict_types=1);

namespace App\Walleting\Service;

use App\Walleting\Entity\Wallet;
use App\Walleting\Entity\WalletFunding;
use App\Walleting\Entity\WalletPaymentInstrument;
use App\Walleting\Entity\WalletProviderEventEntity;
use App\Walleting\Entity\WalletWithdrawal;
use App\Walleting\Enum\WalletFundingStatus;
use App\Walleting\Enum\WalletPaymentInstrumentStatus;
use App\Walleting\Enum\WalletProviderEventStatus;
use App\Walleting\Enum\WalletWithdrawalStatus;
use App\Walleting\ValueObject\Ledger\WalletPostingInstruction;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final readonly class WalletFundingWithdrawalOrchestrator
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private WalletFinancialOperationService $financialOperations,
        private WalletProviderEventService $providerEvents,
    ) {
    }

    public function requestFunding(Wallet $wallet, WalletPaymentInstrument $instrument, int $amountMinor, string $currency, string $idempotencyKey): WalletFunding
    {
        return $this->entityManager->wrapInTransaction(function () use ($wallet, $instrument, $amountMinor, $currency, $idempotencyKey): WalletFunding {
            $existing = $this->entityManager->getRepository(WalletFunding::class)->findOneBy(['idempotencyKey' => trim($idempotencyKey)]);
            if ($existing instanceof WalletFunding) {
                if ($existing->wallet() !== $wallet || $existing->paymentInstrument() !== $instrument || $existing->amountMinor() !== $amountMinor || $existing->currency() !== strtoupper(trim($currency))) {
                    throw new \DomainException('Funding idempotency key is already bound to different request content.');
                }

                return $existing;
            }
            $funding = new WalletFunding($wallet, $instrument, $amountMinor, $currency, $idempotencyKey);
            $this->entityManager->persist($funding);
            $this->entityManager->flush();

            return $funding;
        });
    }

    public function requestWithdrawal(Wallet $wallet, WalletPaymentInstrument $instrument, int $amountMinor, string $currency, string $idempotencyKey): WalletWithdrawal
    {
        return $this->entityManager->wrapInTransaction(function () use ($wallet, $instrument, $amountMinor, $currency, $idempotencyKey): WalletWithdrawal {
            $existing = $this->entityManager->getRepository(WalletWithdrawal::class)->findOneBy(['idempotencyKey' => trim($idempotencyKey)]);
            if ($existing instanceof WalletWithdrawal) {
                if ($existing->wallet() !== $wallet || $existing->paymentInstrument() !== $instrument || $existing->amountMinor() !== $amountMinor || $existing->currency() !== strtoupper(trim($currency))) {
                    throw new \DomainException('Withdrawal idempotency key is already bound to different request content.');
                }

                return $existing;
            }
            $withdrawal = new WalletWithdrawal($wallet, $instrument, $amountMinor, $currency, $idempotencyKey);
            $this->entityManager->persist($withdrawal);
            $this->entityManager->flush();

            return $withdrawal;
        });
    }

    public function beginFunding(WalletFunding $funding): WalletProviderOperationRequest
    {
        return $this->entityManager->wrapInTransaction(function () use ($funding): WalletProviderOperationRequest {
            $this->entityManager->lock($funding, LockMode::PESSIMISTIC_WRITE);
            if (WalletPaymentInstrumentStatus::Active !== $funding->paymentInstrument()->status()) {
                throw new \DomainException('Funding payment instrument must remain active before provider processing begins.');
            }
            if (WalletFundingStatus::Processing === $funding->status()) {
                return $this->requestForFunding($funding);
            }
            $funding->start();
            $this->entityManager->flush();

            return $this->requestForFunding($funding);
        });
    }

    public function beginWithdrawal(WalletWithdrawal $withdrawal): WalletProviderOperationRequest
    {
        return $this->entityManager->wrapInTransaction(function () use ($withdrawal): WalletProviderOperationRequest {
            $this->entityManager->lock($withdrawal, LockMode::PESSIMISTIC_WRITE);
            if (WalletPaymentInstrumentStatus::Active !== $withdrawal->paymentInstrument()->status()) {
                throw new \DomainException('Withdrawal payment instrument must remain active before provider processing begins.');
            }
            if (WalletWithdrawalStatus::Processing === $withdrawal->status()) {
                return $this->requestForWithdrawal($withdrawal);
            }
            $withdrawal->start();
            $this->entityManager->flush();

            return $this->requestForWithdrawal($withdrawal);
        });
    }

    public function bindFundingProviderOperationReference(WalletFunding $funding, string $reference): void
    {
        $this->entityManager->wrapInTransaction(function () use ($funding, $reference): void {
            $this->entityManager->lock($funding, LockMode::PESSIMISTIC_WRITE);
            $funding->bindProviderOperationReference($reference);
            $this->entityManager->flush();
        });
    }

    public function bindWithdrawalProviderOperationReference(WalletWithdrawal $withdrawal, string $reference): void
    {
        $this->entityManager->wrapInTransaction(function () use ($withdrawal, $reference): void {
            $this->entityManager->lock($withdrawal, LockMode::PESSIMISTIC_WRITE);
            $withdrawal->bindProviderOperationReference($reference);
            $this->entityManager->flush();
        });
    }

    /** @param non-empty-list<WalletPostingInstruction> $instructions */
    public function succeedFunding(WalletProviderEventEntity $event, WalletFunding $funding, string $ledgerIdempotencyKey, array $instructions): void
    {
        $this->entityManager->wrapInTransaction(function () use ($event, $funding, $ledgerIdempotencyKey, $instructions): void {
            $this->assertProvider($event, $funding->paymentInstrument());
            if ($this->isFundingReplay($event, $funding, WalletFundingStatus::Succeeded)) {
                return;
            }
            $this->entityManager->lock($funding, LockMode::PESSIMISTIC_WRITE);
            $this->financialOperations->succeedFunding($funding, $ledgerIdempotencyKey, $instructions);
            $this->providerEvents->processFunding($event, $funding);
        });
    }

    public function failFunding(WalletProviderEventEntity $event, WalletFunding $funding): void
    {
        $this->entityManager->wrapInTransaction(function () use ($event, $funding): void {
            $this->assertProvider($event, $funding->paymentInstrument());
            if ($this->isFundingReplay($event, $funding, WalletFundingStatus::Failed)) {
                return;
            }
            $this->entityManager->lock($funding, LockMode::PESSIMISTIC_WRITE);
            $funding->fail();
            $this->providerEvents->processFunding($event, $funding);
            $this->entityManager->flush();
        });
    }

    /** @param non-empty-list<WalletPostingInstruction> $instructions */
    public function succeedWithdrawal(WalletProviderEventEntity $event, WalletWithdrawal $withdrawal, string $ledgerIdempotencyKey, array $instructions): void
    {
        $this->entityManager->wrapInTransaction(function () use ($event, $withdrawal, $ledgerIdempotencyKey, $instructions): void {
            $this->assertProvider($event, $withdrawal->paymentInstrument());
            if ($this->isWithdrawalReplay($event, $withdrawal, WalletWithdrawalStatus::Succeeded)) {
                return;
            }
            $this->entityManager->lock($withdrawal, LockMode::PESSIMISTIC_WRITE);
            $this->financialOperations->succeedWithdrawal($withdrawal, $ledgerIdempotencyKey, $instructions);
            $this->providerEvents->processWithdrawal($event, $withdrawal);
        });
    }

    public function failWithdrawal(WalletProviderEventEntity $event, WalletWithdrawal $withdrawal): void
    {
        $this->entityManager->wrapInTransaction(function () use ($event, $withdrawal): void {
            $this->assertProvider($event, $withdrawal->paymentInstrument());
            if ($this->isWithdrawalReplay($event, $withdrawal, WalletWithdrawalStatus::Failed)) {
                return;
            }
            $this->entityManager->lock($withdrawal, LockMode::PESSIMISTIC_WRITE);
            $withdrawal->fail();
            $this->providerEvents->processWithdrawal($event, $withdrawal);
            $this->entityManager->flush();
        });
    }

    private function isFundingReplay(WalletProviderEventEntity $event, WalletFunding $funding, WalletFundingStatus $expectedStatus): bool
    {
        if (WalletProviderEventStatus::Processed !== $event->status()) {
            return false;
        }
        if ($event->funding() === $funding && $funding->status() === $expectedStatus) {
            return true;
        }

        throw new \DomainException('Processed provider event conflicts with the funding operation or outcome.');
    }

    private function isWithdrawalReplay(WalletProviderEventEntity $event, WalletWithdrawal $withdrawal, WalletWithdrawalStatus $expectedStatus): bool
    {
        if (WalletProviderEventStatus::Processed !== $event->status()) {
            return false;
        }
        if ($event->withdrawal() === $withdrawal && $withdrawal->status() === $expectedStatus) {
            return true;
        }

        throw new \DomainException('Processed provider event conflicts with the withdrawal operation or outcome.');
    }

    private function requestForFunding(WalletFunding $funding): WalletProviderOperationRequest
    {
        $instrument = $funding->paymentInstrument();

        return new WalletProviderOperationRequest('funding', $funding->id()->toRfc4122(), $funding->idempotencyKey(), $instrument->provider(), $instrument->providerReference(), $funding->amountMinor(), $funding->currency());
    }

    private function requestForWithdrawal(WalletWithdrawal $withdrawal): WalletProviderOperationRequest
    {
        $instrument = $withdrawal->paymentInstrument();

        return new WalletProviderOperationRequest('withdrawal', $withdrawal->id()->toRfc4122(), $withdrawal->idempotencyKey(), $instrument->provider(), $instrument->providerReference(), $withdrawal->amountMinor(), $withdrawal->currency());
    }

    private function assertProvider(WalletProviderEventEntity $event, WalletPaymentInstrument $instrument): void
    {
        if ($event->provider() !== $instrument->provider()) {
            throw new \DomainException('Provider event does not match the operation payment instrument provider.');
        }
    }
}
