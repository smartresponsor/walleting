<?php

declare(strict_types=1);

namespace App\Walleting\Tests\Integration;

use App\Walleting\Entity\Account;
use App\Walleting\Entity\PaymentInstrument;
use App\Walleting\Entity\Wallet;
use App\Walleting\Enum\AccountCategory;
use App\Walleting\Enum\FundingStatus;
use App\Walleting\Enum\PaymentInstrumentType;
use App\Walleting\Enum\ProviderEventStatus;
use App\Walleting\Enum\WithdrawalStatus;
use App\Walleting\Ledger\PostingInstruction;
use App\Walleting\Service\FinancialOperationService;
use App\Walleting\Service\FundingWithdrawalOrchestrator;
use App\Walleting\Service\NullPostingTelemetry;
use App\Walleting\Service\OutboxService;
use App\Walleting\Service\PostingDbalExecutor;
use App\Walleting\Service\PostingRetryPolicy;
use App\Walleting\Service\PostingService;
use App\Walleting\Service\ProviderEventService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PostgreSqlFundingWithdrawalOrchestrationTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private FundingWithdrawalOrchestrator $orchestrator;
    private ProviderEventService $providerEvents;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        $connection = $this->entityManager->getConnection();
        $outbox = new OutboxService($this->entityManager, $connection);
        $posting = new PostingService($this->entityManager, $outbox, new PostingDbalExecutor($connection, $outbox, new PostingRetryPolicy(), new NullPostingTelemetry()));
        $financial = new FinancialOperationService($this->entityManager, $posting, $outbox);
        $this->providerEvents = new ProviderEventService($this->entityManager, $outbox);
        $this->orchestrator = new FundingWithdrawalOrchestrator($this->entityManager, $financial, $this->providerEvents);
    }

    public function testFundingProviderSuccessSettlesLedgerAndProcessesEventAtomically(): void
    {
        $wallet = new Wallet('vendor', 'orchestrated-funding-wallet');
        $instrument = new PaymentInstrument($wallet, PaymentInstrumentType::Card, 'stripe', 'pm_orchestrated_funding', 'Card');
        $asset = new Account($wallet, 'asset', 'USD', AccountCategory::Asset);
        $clearing = new Account($wallet, 'clearing', 'USD', AccountCategory::Clearing);
        foreach ([$wallet, $instrument, $asset, $clearing] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();

        $funding = $this->orchestrator->requestFunding($wallet, $instrument, 1000, 'USD', 'orchestrated-funding-request');
        $request = $this->orchestrator->beginFunding($funding);
        self::assertSame(FundingStatus::Processing, $funding->status());
        self::assertSame('stripe', $request->provider);

        $event = $this->providerEvents->receive('stripe', 'evt_orchestrated_funding', 'funding.succeeded', ['operation_id' => $request->operationId]);
        $this->orchestrator->succeedFunding($event, $funding, 'orchestrated-funding-ledger', [
            new PostingInstruction($asset, 1000),
            new PostingInstruction($clearing, -1000),
        ]);

        self::assertSame(FundingStatus::Succeeded, $funding->status());
        self::assertNotNull($funding->transaction());
        self::assertSame(ProviderEventStatus::Processed, $event->status());
        self::assertSame($funding, $event->funding());
        self::assertSame(1000, (int) $this->entityManager->getConnection()->fetchOne('SELECT balance_minor FROM account_balance WHERE account_id = ?', [$asset->id()->toRfc4122()]));

        $transaction = $funding->transaction();
        $this->orchestrator->succeedFunding($event, $funding, 'orchestrated-funding-ledger', [
            new PostingInstruction($asset, 1000),
            new PostingInstruction($clearing, -1000),
        ]);
        self::assertSame($transaction, $funding->transaction());
        self::assertSame(1000, (int) $this->entityManager->getConnection()->fetchOne('SELECT balance_minor FROM account_balance WHERE account_id = ?', [$asset->id()->toRfc4122()]));
    }

    public function testWithdrawalProviderFailureLeavesLedgerUntouchedAndProcessesEvent(): void
    {
        $wallet = new Wallet('vendor', 'orchestrated-withdrawal-wallet');
        $instrument = new PaymentInstrument($wallet, PaymentInstrumentType::BankAccount, 'ach', 'bank_orchestrated_withdrawal', 'Bank');
        foreach ([$wallet, $instrument] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();

        $withdrawal = $this->orchestrator->requestWithdrawal($wallet, $instrument, 700, 'USD', 'orchestrated-withdrawal-request');
        $request = $this->orchestrator->beginWithdrawal($withdrawal);
        self::assertSame(WithdrawalStatus::Processing, $withdrawal->status());

        $event = $this->providerEvents->receive('ach', 'evt_orchestrated_withdrawal', 'withdrawal.failed', ['operation_id' => $request->operationId]);
        $this->orchestrator->failWithdrawal($event, $withdrawal);

        self::assertSame(WithdrawalStatus::Failed, $withdrawal->status());
        self::assertNull($withdrawal->transaction());
        self::assertSame(ProviderEventStatus::Processed, $event->status());
        self::assertSame($withdrawal, $event->withdrawal());

        $this->orchestrator->failWithdrawal($event, $withdrawal);
        self::assertSame(WithdrawalStatus::Failed, $withdrawal->status());
        self::assertNull($withdrawal->transaction());
    }
}
