<?php

declare(strict_types=1);

namespace App\Walleting\Tests\Integration;

use App\Walleting\Entity\Wallet;
use App\Walleting\Entity\WalletAccount;
use App\Walleting\Enum\WalletAccountCategory;
use App\Walleting\Enum\WalletReservationStatus;
use App\Walleting\Policy\Posting\WalletPostingRetryPolicy;
use App\Walleting\Service\WalletFeePostingComposer;
use App\Walleting\Service\WalletFinancialOperationService;
use App\Walleting\Service\WalletNullPostingTelemetry;
use App\Walleting\Service\WalletOutboxService;
use App\Walleting\Service\WalletPostingDbalExecutor;
use App\Walleting\Service\WalletPostingService;
use App\Walleting\ValueObject\Ledger\WalletFeeAllocation;
use App\Walleting\ValueObject\Ledger\WalletPostingInstruction;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PostgreSqlFeeSettlementTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private WalletFinancialOperationService $operations;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        $connection = $this->entityManager->getConnection();
        $outbox = new WalletOutboxService($this->entityManager, $connection);
        $posting = new WalletPostingService(
            $this->entityManager,
            $outbox,
            new WalletPostingDbalExecutor($connection, $outbox, new WalletPostingRetryPolicy(), new WalletNullPostingTelemetry()),
        );
        $this->operations = new WalletFinancialOperationService($this->entityManager, $posting, $outbox, new WalletFeePostingComposer());
        self::assertInstanceOf(\Doctrine\DBAL\Platforms\PostgreSQLPlatform::class, $connection->getDatabasePlatform());
    }

    public function testCaptureWithFeesPostsGrossNetAndCommissionsAtomically(): void
    {
        $wallet = new Wallet('vendor', 'fee-settlement-wallet');
        $reserve = new WalletAccount($wallet, 'reserve', 'USD', WalletAccountCategory::Reserve);
        $funding = new WalletAccount($wallet, 'funding-clearing', 'USD', WalletAccountCategory::Clearing);
        $vendorNet = new WalletAccount($wallet, 'vendor-net', 'USD', WalletAccountCategory::Liability);
        $platformFee = new WalletAccount($wallet, 'platform-fee', 'USD', WalletAccountCategory::Revenue);
        $providerFee = new WalletAccount($wallet, 'provider-fee', 'USD', WalletAccountCategory::Clearing);
        foreach ([$wallet, $reserve, $funding, $vendorNet, $platformFee, $providerFee] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();

        $reservation = $this->operations->reserve($wallet, $reserve, 1000, 'USD', 'fee-reserve-1', [
            new WalletPostingInstruction($funding, -1000),
            new WalletPostingInstruction($reserve, 1000),
        ]);
        $transaction = $this->operations->captureWithFees($reservation, $vendorNet, [
            new WalletFeeAllocation('platform_fee', $platformFee, 100),
            new WalletFeeAllocation('provider_fee', $providerFee, 50),
        ], 'fee-capture-1');

        self::assertSame(WalletReservationStatus::Captured, $reservation->status());
        self::assertCount(4, $transaction->postings());
        self::assertSame([-1000, 850, 100, 50], array_map(static fn ($posting): int => $posting->amountMinor(), $transaction->postings()->toArray()));
        self::assertSame(1000, $transaction->metadata()['settlement']['gross_amount_minor']);
        self::assertSame(850, $transaction->metadata()['settlement']['net_amount_minor']);
        self::assertSame(150, $transaction->metadata()['settlement']['fee_amount_minor']);
        self::assertSame('platform_fee', $transaction->metadata()['settlement']['fees'][0]['code']);

        $balances = [];
        foreach ([$reserve, $vendorNet, $platformFee, $providerFee] as $account) {
            $balances[$account->code()] = (int) $this->entityManager->getConnection()->fetchOne('SELECT balance_minor FROM account_balance WHERE account_id = ?', [$account->id()->toRfc4122()]);
        }
        self::assertSame(0, $balances['reserve']);
        self::assertSame(850, $balances['vendor-net']);
        self::assertSame(100, $balances['platform-fee']);
        self::assertSame(50, $balances['provider-fee']);
    }
}
