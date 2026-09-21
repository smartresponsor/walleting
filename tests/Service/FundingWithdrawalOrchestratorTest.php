<?php

declare(strict_types=1);

namespace App\Walleting\Tests\Service;

use App\Walleting\Entity\Wallet;
use App\Walleting\Entity\WalletFunding;
use App\Walleting\Entity\WalletPaymentInstrument;
use App\Walleting\Entity\WalletProviderEventEntity;
use App\Walleting\Entity\WalletWithdrawal;
use App\Walleting\Enum\WalletFundingStatus;
use App\Walleting\Enum\WalletPaymentInstrumentType;
use App\Walleting\Enum\WalletWithdrawalStatus;
use App\Walleting\Policy\Posting\WalletPostingRetryPolicy;
use App\Walleting\Service\WalletFinancialOperationService;
use App\Walleting\Service\WalletFundingWithdrawalOrchestrator;
use App\Walleting\Service\WalletNullPostingTelemetry;
use App\Walleting\Service\WalletOutboxService;
use App\Walleting\Service\WalletPostingDbalExecutor;
use App\Walleting\Service\WalletPostingService;
use App\Walleting\Service\WalletProviderEventService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

final class FundingWithdrawalOrchestratorTest extends TestCase
{
    public function testBeginFundingProducesProviderNeutralRequestAndMovesToProcessing(): void
    {
        $entityManager = $this->entityManager();
        $orchestrator = $this->orchestrator($entityManager);
        $wallet = new Wallet('vendor', 'orchestration-wallet');
        $instrument = new WalletPaymentInstrument($wallet, WalletPaymentInstrumentType::Card, 'stripe', 'pm_orchestration', 'Card');
        $funding = new WalletFunding($wallet, $instrument, 1200, 'USD', 'funding-orchestration-1');

        $request = $orchestrator->beginFunding($funding);

        self::assertSame(WalletFundingStatus::Processing, $funding->status());
        self::assertSame('funding', $request->operation);
        self::assertSame($funding->id()->toRfc4122(), $request->operationId);
        self::assertSame('stripe', $request->provider);
        self::assertSame('pm_orchestration', $request->providerReference);
        self::assertSame(1200, $request->amountMinor);
        self::assertSame('USD', $request->currency);

        $replayed = $orchestrator->beginFunding($funding);
        self::assertSame($request->operationId, $replayed->operationId);
        self::assertSame(WalletFundingStatus::Processing, $funding->status());
    }

    public function testProviderEventMustMatchPaymentInstrumentProvider(): void
    {
        $entityManager = $this->entityManager();
        $orchestrator = $this->orchestrator($entityManager);
        $wallet = new Wallet('vendor', 'provider-mismatch-wallet');
        $instrument = new WalletPaymentInstrument($wallet, WalletPaymentInstrumentType::Card, 'stripe', 'pm_provider_mismatch', 'Card');
        $funding = new WalletFunding($wallet, $instrument, 500, 'USD', 'provider-mismatch-funding');
        $orchestrator->beginFunding($funding);
        $event = new WalletProviderEventEntity('adyen', 'evt_provider_mismatch', 'funding.succeeded', ['amount_minor' => 500]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Provider event does not match the operation payment instrument provider.');
        $orchestrator->failFunding($event, $funding);
    }

    public function testBeginFundingRejectsInstrumentDisabledAfterRequestCreation(): void
    {
        $entityManager = $this->entityManager();
        $orchestrator = $this->orchestrator($entityManager);
        $wallet = new Wallet('vendor', 'funding-disabled-before-begin');
        $instrument = new WalletPaymentInstrument($wallet, WalletPaymentInstrumentType::Card, 'stripe', 'pm_disabled_before_begin', 'Card');
        $funding = new WalletFunding($wallet, $instrument, 1200, 'USD', 'funding-disabled-before-begin');
        $instrument->disable();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Funding payment instrument must remain active before provider processing begins.');

        $orchestrator->beginFunding($funding);
    }

    public function testBeginWithdrawalProducesProviderNeutralRequestAndMovesToProcessing(): void
    {
        $entityManager = $this->entityManager();
        $orchestrator = $this->orchestrator($entityManager);
        $wallet = new Wallet('vendor', 'withdrawal-orchestration-wallet');
        $instrument = new WalletPaymentInstrument($wallet, WalletPaymentInstrumentType::BankAccount, 'ach', 'bank_1', 'Bank');
        $withdrawal = new WalletWithdrawal($wallet, $instrument, 900, 'USD', 'withdrawal-orchestration-1');

        $request = $orchestrator->beginWithdrawal($withdrawal);

        self::assertSame(WalletWithdrawalStatus::Processing, $withdrawal->status());
        self::assertSame('withdrawal', $request->operation);
        self::assertSame('ach', $request->provider);
        self::assertSame('bank_1', $request->providerReference);
        self::assertSame(900, $request->amountMinor);
    }

    public function testBeginWithdrawalRejectsInstrumentExpiredAfterRequestCreation(): void
    {
        $entityManager = $this->entityManager();
        $orchestrator = $this->orchestrator($entityManager);
        $wallet = new Wallet('vendor', 'withdrawal-expired-before-begin');
        $instrument = new WalletPaymentInstrument($wallet, WalletPaymentInstrumentType::BankAccount, 'ach', 'bank_expired_before_begin', 'Bank');
        $withdrawal = new WalletWithdrawal($wallet, $instrument, 900, 'USD', 'withdrawal-expired-before-begin');
        $instrument->expire();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Withdrawal payment instrument must remain active before provider processing begins.');

        $orchestrator->beginWithdrawal($withdrawal);
    }

    private function orchestrator(EntityManagerInterface $entityManager): WalletFundingWithdrawalOrchestrator
    {
        $connection = $this->createStub(Connection::class);
        $outbox = new WalletOutboxService($entityManager, $connection);
        $posting = new WalletPostingService($entityManager, $outbox, new WalletPostingDbalExecutor($connection, $outbox, new WalletPostingRetryPolicy(), new WalletNullPostingTelemetry()));
        $financial = new WalletFinancialOperationService($entityManager, $posting, $outbox);

        return new WalletFundingWithdrawalOrchestrator($entityManager, $financial, new WalletProviderEventService($entityManager, $outbox));
    }

    private function entityManager(): EntityManagerInterface
    {
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findOneBy')->willReturn(null);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);
        $entityManager->method('wrapInTransaction')->willReturnCallback(static fn (callable $callback): mixed => $callback());

        return $entityManager;
    }
}
