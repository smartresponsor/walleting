<?php

declare(strict_types=1);

namespace App\Walleting\Tests\Integration;

use App\Walleting\Entity\Wallet;
use App\Walleting\Entity\WalletAccount;
use App\Walleting\Entity\WalletPaymentInstrument;
use App\Walleting\Enum\WalletAccountCategory;
use App\Walleting\Enum\WalletFundingStatus;
use App\Walleting\Enum\WalletPaymentInstrumentType;
use App\Walleting\Enum\WalletProviderEventStatus;
use App\Walleting\Enum\WalletWithdrawalStatus;
use App\Walleting\Policy\Posting\WalletPostingRetryPolicy;
use App\Walleting\Service\WalletFinancialOperationService;
use App\Walleting\Service\WalletFundingWithdrawalOrchestrator;
use App\Walleting\Service\WalletNullPostingTelemetry;
use App\Walleting\Service\WalletOutboxService;
use App\Walleting\Service\WalletPostingDbalExecutor;
use App\Walleting\Service\WalletPostingService;
use App\Walleting\Service\WalletProviderEventService;
use App\Walleting\ValueObject\Ledger\WalletPostingInstruction;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PostgreSqlFundingWithdrawalOrchestrationTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private WalletFundingWithdrawalOrchestrator $orchestrator;
    private WalletProviderEventService $providerEvents;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        $connection = $this->entityManager->getConnection();
        $outbox = new WalletOutboxService($this->entityManager, $connection);
        $posting = new WalletPostingService($this->entityManager, $outbox, new WalletPostingDbalExecutor($connection, $outbox, new WalletPostingRetryPolicy(), new WalletNullPostingTelemetry()));
        $financial = new WalletFinancialOperationService($this->entityManager, $posting, $outbox);
        $this->providerEvents = new WalletProviderEventService($this->entityManager, $outbox);
        $this->orchestrator = new WalletFundingWithdrawalOrchestrator($this->entityManager, $financial, $this->providerEvents);
    }

    public function testFundingProviderSuccessSettlesLedgerAndProcessesEventAtomically(): void
    {
        $wallet = new Wallet('vendor', 'orchestrated-funding-wallet');
        $instrument = new WalletPaymentInstrument($wallet, WalletPaymentInstrumentType::Card, 'stripe', 'pm_orchestrated_funding', 'Card');
        $asset = new WalletAccount($wallet, 'asset', 'USD', WalletAccountCategory::Asset);
        $clearing = new WalletAccount($wallet, 'clearing', 'USD', WalletAccountCategory::Clearing);
        foreach ([$wallet, $instrument, $asset, $clearing] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();

        $funding = $this->orchestrator->requestFunding($wallet, $instrument, 1000, 'USD', 'orchestrated-funding-request');
        $request = $this->orchestrator->beginFunding($funding);
        self::assertSame(WalletFundingStatus::Processing, $funding->status());
        self::assertSame('stripe', $request->provider);
        $this->orchestrator->bindFundingProviderOperationReference($funding, 'ch_orchestrated_funding');
        self::assertSame('ch_orchestrated_funding', $funding->providerOperationReference());

        $event = $this->providerEvents->receive('stripe', 'evt_orchestrated_funding', 'funding.succeeded', ['operation_id' => $request->operationId]);
        $this->orchestrator->succeedFunding($event, $funding, 'orchestrated-funding-ledger', [
            new WalletPostingInstruction($asset, 1000),
            new WalletPostingInstruction($clearing, -1000),
        ]);

        self::assertSame(WalletFundingStatus::Succeeded, $funding->status());
        self::assertNotNull($funding->transaction());
        self::assertSame(WalletProviderEventStatus::Processed, $event->status());
        self::assertSame($funding, $event->funding());
        self::assertSame(1000, (int) $this->entityManager->getConnection()->fetchOne('SELECT balance_minor FROM account_balance WHERE account_id = ?', [$asset->id()->toRfc4122()]));

        $transaction = $funding->transaction();
        $this->orchestrator->succeedFunding($event, $funding, 'orchestrated-funding-ledger', [
            new WalletPostingInstruction($asset, 1000),
            new WalletPostingInstruction($clearing, -1000),
        ]);
        self::assertSame($transaction, $funding->transaction());
        self::assertSame(1000, (int) $this->entityManager->getConnection()->fetchOne('SELECT balance_minor FROM account_balance WHERE account_id = ?', [$asset->id()->toRfc4122()]));
    }

    public function testWithdrawalProviderFailureLeavesLedgerUntouchedAndProcessesEvent(): void
    {
        $wallet = new Wallet('vendor', 'orchestrated-withdrawal-wallet');
        $instrument = new WalletPaymentInstrument($wallet, WalletPaymentInstrumentType::BankAccount, 'ach', 'bank_orchestrated_withdrawal', 'Bank');
        foreach ([$wallet, $instrument] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();

        $withdrawal = $this->orchestrator->requestWithdrawal($wallet, $instrument, 700, 'USD', 'orchestrated-withdrawal-request');
        $request = $this->orchestrator->beginWithdrawal($withdrawal);
        self::assertSame(WalletWithdrawalStatus::Processing, $withdrawal->status());
        $this->orchestrator->bindWithdrawalProviderOperationReference($withdrawal, 'po_orchestrated_withdrawal');
        self::assertSame('po_orchestrated_withdrawal', $withdrawal->providerOperationReference());

        $event = $this->providerEvents->receive('ach', 'evt_orchestrated_withdrawal', 'withdrawal.failed', ['operation_id' => $request->operationId]);
        $this->orchestrator->failWithdrawal($event, $withdrawal);

        self::assertSame(WalletWithdrawalStatus::Failed, $withdrawal->status());
        self::assertNull($withdrawal->transaction());
        self::assertSame(WalletProviderEventStatus::Processed, $event->status());
        self::assertSame($withdrawal, $event->withdrawal());

        $this->orchestrator->failWithdrawal($event, $withdrawal);
        self::assertSame(WalletWithdrawalStatus::Failed, $withdrawal->status());
        self::assertNull($withdrawal->transaction());
    }
}
