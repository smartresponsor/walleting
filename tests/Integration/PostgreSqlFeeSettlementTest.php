<?php

declare(strict_types=1);

namespace App\Walleting\Tests\Integration;

use App\Walleting\Entity\Account;
use App\Walleting\Entity\Wallet;
use App\Walleting\Enum\AccountCategory;
use App\Walleting\Enum\ReservationStatus;
use App\Walleting\Ledger\FeeAllocation;
use App\Walleting\Ledger\PostingInstruction;
use App\Walleting\Service\FeePostingComposer;
use App\Walleting\Service\FinancialOperationService;
use App\Walleting\Service\NullPostingTelemetry;
use App\Walleting\Service\OutboxService;
use App\Walleting\Service\PostingDbalExecutor;
use App\Walleting\Service\PostingRetryPolicy;
use App\Walleting\Service\PostingService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PostgreSqlFeeSettlementTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private FinancialOperationService $operations;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        $connection = $this->entityManager->getConnection();
        $outbox = new OutboxService($this->entityManager, $connection);
        $posting = new PostingService(
            $this->entityManager,
            $outbox,
            new PostingDbalExecutor($connection, $outbox, new PostingRetryPolicy(), new NullPostingTelemetry()),
        );
        $this->operations = new FinancialOperationService($this->entityManager, $posting, $outbox, new FeePostingComposer());
        self::assertInstanceOf(\Doctrine\DBAL\Platforms\PostgreSQLPlatform::class, $connection->getDatabasePlatform());
    }

    public function testCaptureWithFeesPostsGrossNetAndCommissionsAtomically(): void
    {
        $wallet = new Wallet('vendor', 'fee-settlement-wallet');
        $reserve = new Account($wallet, 'reserve', 'USD', AccountCategory::Reserve);
        $funding = new Account($wallet, 'funding-clearing', 'USD', AccountCategory::Clearing);
        $vendorNet = new Account($wallet, 'vendor-net', 'USD', AccountCategory::Liability);
        $platformFee = new Account($wallet, 'platform-fee', 'USD', AccountCategory::Revenue);
        $providerFee = new Account($wallet, 'provider-fee', 'USD', AccountCategory::Clearing);
        foreach ([$wallet, $reserve, $funding, $vendorNet, $platformFee, $providerFee] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();

        $reservation = $this->operations->reserve($wallet, $reserve, 1000, 'USD', 'fee-reserve-1', [
            new PostingInstruction($funding, -1000),
            new PostingInstruction($reserve, 1000),
        ]);
        $transaction = $this->operations->captureWithFees($reservation, $vendorNet, [
            new FeeAllocation('platform_fee', $platformFee, 100),
            new FeeAllocation('provider_fee', $providerFee, 50),
        ], 'fee-capture-1');

        self::assertSame(ReservationStatus::Captured, $reservation->status());
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
