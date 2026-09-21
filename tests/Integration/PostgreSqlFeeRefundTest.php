<?php

declare(strict_types=1);

namespace App\Walleting\Tests\Integration;

use App\Walleting\Entity\Wallet;
use App\Walleting\Entity\WalletAccount;
use App\Walleting\Enum\WalletAccountCategory;
use App\Walleting\Enum\WalletTransactionType;
use App\Walleting\Policy\Posting\WalletPostingRetryPolicy;
use App\Walleting\Service\WalletFeePostingComposer;
use App\Walleting\Service\WalletFinancialOperationService;
use App\Walleting\Service\WalletNullPostingTelemetry;
use App\Walleting\Service\WalletOutboxService;
use App\Walleting\Service\WalletPostingDbalExecutor;
use App\Walleting\Service\WalletPostingService;
use App\Walleting\ValueObject\Ledger\WalletFeeAllocation;
use App\Walleting\ValueObject\Ledger\WalletPostingInstruction;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class PostgreSqlFeeRefundTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private WalletFinancialOperationService $operations;
    private WalletPostingService $postingService;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        $connection = $this->entityManager->getConnection();
        $outbox = new WalletOutboxService($this->entityManager, $connection);
        $this->postingService = new WalletPostingService($this->entityManager, $outbox, new WalletPostingDbalExecutor($connection, $outbox, new WalletPostingRetryPolicy(), new WalletNullPostingTelemetry()));
        $this->operations = new WalletFinancialOperationService($this->entityManager, $this->postingService, $outbox, new WalletFeePostingComposer());
    }

    public function testFeeBearingCaptureSupportsExplicitMultiLegPartialRefund(): void
    {
        [$reserve, $vendor, $platform, $provider, $capture] = $this->feeCapture('allocated-refund');

        $refund = $this->operations->refundPartialAllocated($capture, 200, 'allocated-refund-1', [
            new WalletPostingInstruction($reserve, 200),
            new WalletPostingInstruction($vendor, -170),
            new WalletPostingInstruction($platform, -20),
            new WalletPostingInstruction($provider, -10),
        ]);

        self::assertSame([200, -170, -20, -10], array_map(static fn ($posting): int => $posting->amountMinor(), $refund->postings()->toArray()));
        self::assertSame(200, (int) $this->entityManager->getConnection()->fetchOne('SELECT amount_minor FROM financial_operation_link WHERE result_transaction_id = ?', [$refund->id()->toRfc4122()]));
    }

    public function testCumulativeRefundCannotOverdrawOneOriginalFeeBearingLeg(): void
    {
        [$reserve, $vendor, $platform, $provider, $capture] = $this->feeCapture('leg-cap');
        $this->operations->refundPartialAllocated($capture, 200, 'leg-cap-refund-1', [
            new WalletPostingInstruction($reserve, 200),
            new WalletPostingInstruction($vendor, -200),
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Cumulative refund exceeds an original transaction account leg.');
        $this->operations->refundPartialAllocated($capture, 700, 'leg-cap-refund-2', [
            new WalletPostingInstruction($reserve, 700),
            new WalletPostingInstruction($vendor, -700),
        ]);
    }

    public function testDatabaseRejectsRefundThatOverdrawsOneOriginalLegViaRawLinkInsert(): void
    {
        [$reserve, $vendor, $platform, $provider, $capture] = $this->feeCapture('raw-leg-cap');
        $refund = $this->postingService->post(WalletTransactionType::Refund, 'raw-leg-cap-refund', [
            new WalletPostingInstruction($reserve, 900),
            new WalletPostingInstruction($vendor, -900),
        ]);

        $this->expectException(DriverException::class);
        $this->expectExceptionMessage('refund must invert only original transaction account legs');
        $this->entityManager->getConnection()->insert('financial_operation_link', [
            'id' => Uuid::v7()->toRfc4122(),
            'operation_type' => WalletTransactionType::Refund->value,
            'source_transaction_id' => $capture->id()->toRfc4122(),
            'result_transaction_id' => $refund->id()->toRfc4122(),
            'reservation_id' => null,
            'amount_minor' => 900,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    /** @return array{Account,Account,Account,Account,\App\Walleting\Entity\LedgerTransaction} */
    private function feeCapture(string $prefix): array
    {
        $wallet = new Wallet('vendor', $prefix.'-wallet');
        $reserve = new WalletAccount($wallet, 'reserve', 'USD', WalletAccountCategory::Reserve);
        $funding = new WalletAccount($wallet, 'funding', 'USD', WalletAccountCategory::Clearing);
        $vendor = new WalletAccount($wallet, 'vendor-net', 'USD', WalletAccountCategory::Liability);
        $platform = new WalletAccount($wallet, 'platform-fee', 'USD', WalletAccountCategory::Revenue);
        $provider = new WalletAccount($wallet, 'provider-fee', 'USD', WalletAccountCategory::Clearing);
        foreach ([$wallet, $reserve, $funding, $vendor, $platform, $provider] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();

        $reservation = $this->operations->reserve($wallet, $reserve, 1000, 'USD', $prefix.'-reserve', [
            new WalletPostingInstruction($funding, -1000),
            new WalletPostingInstruction($reserve, 1000),
        ]);
        $capture = $this->operations->captureWithFees($reservation, $vendor, [
            new WalletFeeAllocation('platform_fee', $platform, 100),
            new WalletFeeAllocation('provider_fee', $provider, 50),
        ], $prefix.'-capture');

        return [$reserve, $vendor, $platform, $provider, $capture];
    }
}
