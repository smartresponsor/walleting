<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Account;
use App\Entity\Wallet;
use App\Enum\AccountCategory;
use App\Enum\TransactionType;
use App\Ledger\FeeAllocation;
use App\Ledger\PostingInstruction;
use App\Service\FeePostingComposer;
use App\Service\FinancialOperationService;
use App\Service\NullPostingTelemetry;
use App\Service\OutboxService;
use App\Service\PostingDbalExecutor;
use App\Service\PostingRetryPolicy;
use App\Service\PostingService;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class PostgreSqlFeeRefundTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private FinancialOperationService $operations;
    private PostingService $postingService;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        $connection = $this->entityManager->getConnection();
        $outbox = new OutboxService($this->entityManager, $connection);
        $this->postingService = new PostingService($this->entityManager, $outbox, new PostingDbalExecutor($connection, $outbox, new PostingRetryPolicy(), new NullPostingTelemetry()));
        $this->operations = new FinancialOperationService($this->entityManager, $this->postingService, $outbox, new FeePostingComposer());
    }

    public function testFeeBearingCaptureSupportsExplicitMultiLegPartialRefund(): void
    {
        [$reserve, $vendor, $platform, $provider, $capture] = $this->feeCapture('allocated-refund');

        $refund = $this->operations->refundPartialAllocated($capture, 200, 'allocated-refund-1', [
            new PostingInstruction($reserve, 200),
            new PostingInstruction($vendor, -170),
            new PostingInstruction($platform, -20),
            new PostingInstruction($provider, -10),
        ]);

        self::assertSame([200, -170, -20, -10], array_map(static fn ($posting): int => $posting->amountMinor(), $refund->postings()->toArray()));
        self::assertSame(200, (int) $this->entityManager->getConnection()->fetchOne("SELECT amount_minor FROM financial_operation_link WHERE result_transaction_id = ?", [$refund->id()->toRfc4122()]));
    }

    public function testCumulativeRefundCannotOverdrawOneOriginalFeeBearingLeg(): void
    {
        [$reserve, $vendor, $platform, $provider, $capture] = $this->feeCapture('leg-cap');
        $this->operations->refundPartialAllocated($capture, 200, 'leg-cap-refund-1', [
            new PostingInstruction($reserve, 200),
            new PostingInstruction($vendor, -200),
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Cumulative refund exceeds an original transaction account leg.');
        $this->operations->refundPartialAllocated($capture, 700, 'leg-cap-refund-2', [
            new PostingInstruction($reserve, 700),
            new PostingInstruction($vendor, -700),
        ]);
    }

    public function testDatabaseRejectsRefundThatOverdrawsOneOriginalLegViaRawLinkInsert(): void
    {
        [$reserve, $vendor, $platform, $provider, $capture] = $this->feeCapture('raw-leg-cap');
        $refund = $this->postingService->post(TransactionType::Refund, 'raw-leg-cap-refund', [
            new PostingInstruction($reserve, 900),
            new PostingInstruction($vendor, -900),
        ]);

        $this->expectException(DriverException::class);
        $this->expectExceptionMessage('refund must invert only original transaction account legs');
        $this->entityManager->getConnection()->insert('financial_operation_link', [
            'id' => Uuid::v7()->toRfc4122(),
            'operation_type' => TransactionType::Refund->value,
            'source_transaction_id' => $capture->id()->toRfc4122(),
            'result_transaction_id' => $refund->id()->toRfc4122(),
            'reservation_id' => null,
            'amount_minor' => 900,
            'object_created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    /** @return array{Account,Account,Account,Account,\App\Entity\LedgerTransaction} */
    private function feeCapture(string $prefix): array
    {
        $wallet = new Wallet('vendor', $prefix.'-wallet');
        $reserve = new Account($wallet, 'reserve', 'USD', AccountCategory::Reserve);
        $funding = new Account($wallet, 'funding', 'USD', AccountCategory::Clearing);
        $vendor = new Account($wallet, 'vendor-net', 'USD', AccountCategory::Liability);
        $platform = new Account($wallet, 'platform-fee', 'USD', AccountCategory::Revenue);
        $provider = new Account($wallet, 'provider-fee', 'USD', AccountCategory::Clearing);
        foreach ([$wallet, $reserve, $funding, $vendor, $platform, $provider] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();

        $reservation = $this->operations->reserve($wallet, $reserve, 1000, 'USD', $prefix.'-reserve', [
            new PostingInstruction($funding, -1000),
            new PostingInstruction($reserve, 1000),
        ]);
        $capture = $this->operations->captureWithFees($reservation, $vendor, [
            new FeeAllocation('platform_fee', $platform, 100),
            new FeeAllocation('provider_fee', $provider, 50),
        ], $prefix.'-capture');

        return [$reserve, $vendor, $platform, $provider, $capture];
    }
}
