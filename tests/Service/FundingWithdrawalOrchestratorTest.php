<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Funding;
use App\Entity\PaymentInstrument;
use App\Entity\ProviderEvent;
use App\Entity\Wallet;
use App\Entity\Withdrawal;
use App\Enum\FundingStatus;
use App\Enum\PaymentInstrumentType;
use App\Enum\WithdrawalStatus;
use App\Service\FinancialOperationService;
use App\Service\FundingWithdrawalOrchestrator;
use App\Service\NullPostingTelemetry;
use App\Service\OutboxService;
use App\Service\PostingDbalExecutor;
use App\Service\PostingRetryPolicy;
use App\Service\PostingService;
use App\Service\ProviderEventService;
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
        $instrument = new PaymentInstrument($wallet, PaymentInstrumentType::Card, 'stripe', 'pm_orchestration', 'Card');
        $funding = new Funding($wallet, $instrument, 1200, 'USD', 'funding-orchestration-1');

        $request = $orchestrator->beginFunding($funding);

        self::assertSame(FundingStatus::Processing, $funding->status());
        self::assertSame('funding', $request->operation);
        self::assertSame($funding->id()->toRfc4122(), $request->operationId);
        self::assertSame('stripe', $request->provider);
        self::assertSame('pm_orchestration', $request->providerReference);
        self::assertSame(1200, $request->amountMinor);
        self::assertSame('USD', $request->currency);

        $replayed = $orchestrator->beginFunding($funding);
        self::assertSame($request->operationId, $replayed->operationId);
        self::assertSame(FundingStatus::Processing, $funding->status());
    }

    public function testProviderEventMustMatchPaymentInstrumentProvider(): void
    {
        $entityManager = $this->entityManager();
        $orchestrator = $this->orchestrator($entityManager);
        $wallet = new Wallet('vendor', 'provider-mismatch-wallet');
        $instrument = new PaymentInstrument($wallet, PaymentInstrumentType::Card, 'stripe', 'pm_provider_mismatch', 'Card');
        $funding = new Funding($wallet, $instrument, 500, 'USD', 'provider-mismatch-funding');
        $orchestrator->beginFunding($funding);
        $event = new ProviderEvent('adyen', 'evt_provider_mismatch', 'funding.succeeded', ['amount_minor' => 500]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Provider event does not match the operation payment instrument provider.');
        $orchestrator->failFunding($event, $funding);
    }

    public function testBeginWithdrawalProducesProviderNeutralRequestAndMovesToProcessing(): void
    {
        $entityManager = $this->entityManager();
        $orchestrator = $this->orchestrator($entityManager);
        $wallet = new Wallet('vendor', 'withdrawal-orchestration-wallet');
        $instrument = new PaymentInstrument($wallet, PaymentInstrumentType::BankAccount, 'ach', 'bank_1', 'Bank');
        $withdrawal = new Withdrawal($wallet, $instrument, 900, 'USD', 'withdrawal-orchestration-1');

        $request = $orchestrator->beginWithdrawal($withdrawal);

        self::assertSame(WithdrawalStatus::Processing, $withdrawal->status());
        self::assertSame('withdrawal', $request->operation);
        self::assertSame('ach', $request->provider);
        self::assertSame('bank_1', $request->providerReference);
        self::assertSame(900, $request->amountMinor);
    }

    private function orchestrator(EntityManagerInterface $entityManager): FundingWithdrawalOrchestrator
    {
        $connection = $this->createStub(Connection::class);
        $outbox = new OutboxService($entityManager, $connection);
        $posting = new PostingService($entityManager, $outbox, new PostingDbalExecutor($connection, $outbox, new PostingRetryPolicy(), new NullPostingTelemetry()));
        $financial = new FinancialOperationService($entityManager, $posting, $outbox);

        return new FundingWithdrawalOrchestrator($entityManager, $financial, new ProviderEventService($entityManager, $outbox));
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
