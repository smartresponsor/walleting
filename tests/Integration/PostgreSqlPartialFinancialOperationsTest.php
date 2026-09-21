<?php

declare(strict_types=1);

namespace App\Walleting\Tests\Integration;

use App\Walleting\Entity\Wallet;
use App\Walleting\Entity\WalletAccount;
use App\Walleting\Entity\WalletFinancialOperationLink;
use App\Walleting\Enum\WalletAccountCategory;
use App\Walleting\Enum\WalletReservationStatus;
use App\Walleting\Enum\WalletTransactionType;
use App\Walleting\Policy\Posting\WalletPostingRetryPolicy;
use App\Walleting\Service\WalletFinancialOperationService;
use App\Walleting\Service\WalletNullPostingTelemetry;
use App\Walleting\Service\WalletOutboxService;
use App\Walleting\Service\WalletPostingDbalExecutor;
use App\Walleting\Service\WalletPostingService;
use App\Walleting\ValueObject\Ledger\WalletPostingInstruction;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PostgreSqlPartialFinancialOperationsTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private WalletFinancialOperationService $operations;
    private WalletPostingService $postingService;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        $connection = $this->entityManager->getConnection();
        $outboxService = new WalletOutboxService($this->entityManager, $connection);
        $this->postingService = new WalletPostingService(
            $this->entityManager,
            $outboxService,
            new WalletPostingDbalExecutor($connection, $outboxService, new WalletPostingRetryPolicy(), new WalletNullPostingTelemetry()),
        );
        $this->operations = new WalletFinancialOperationService($this->entityManager, $this->postingService, $outboxService);
        self::assertInstanceOf(\Doctrine\DBAL\Platforms\PostgreSQLPlatform::class, $connection->getDatabasePlatform());
    }

    public function testReservationCanBePartiallyCapturedThenReleasedToMixedSettlement(): void
    {
        $wallet = new Wallet('vendor', 'partial-reservation-wallet');
        $reserved = new WalletAccount($wallet, 'reserved', 'USD', WalletAccountCategory::Reserve);
        $clearing = new WalletAccount($wallet, 'clearing', 'USD', WalletAccountCategory::Clearing);
        foreach ([$wallet, $reserved, $clearing] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();

        $reservation = $this->operations->reserve($wallet, $reserved, 500, 'USD', 'partial-reserve-1', [
            new WalletPostingInstruction($clearing, -500),
            new WalletPostingInstruction($reserved, 500),
        ]);
        $this->operations->capturePartial($reservation, 200, 'partial-capture-1', [
            new WalletPostingInstruction($reserved, -200),
            new WalletPostingInstruction($clearing, 200),
        ]);
        self::assertSame(WalletReservationStatus::PartiallySettled, $reservation->status());

        $this->operations->releasePartial($reservation, 300, 'partial-release-1', [
            new WalletPostingInstruction($reserved, -300),
            new WalletPostingInstruction($clearing, 300),
        ]);
        self::assertSame(WalletReservationStatus::Settled, $reservation->status());

        $links = $this->entityManager->getRepository(WalletFinancialOperationLink::class)->findBy(['reservation' => $reservation], ['amountMinor' => 'ASC']);
        self::assertCount(2, $links);
        self::assertSame([200, 300], array_map(static fn (WalletFinancialOperationLink $link): int => $link->amountMinor(), $links));
    }

    public function testTwoPartialRefundsCanConsumeExactlyTheSourceAmount(): void
    {
        $wallet = new Wallet('vendor', 'partial-refund-wallet');
        $asset = new WalletAccount($wallet, 'asset', 'USD', WalletAccountCategory::Asset);
        $clearing = new WalletAccount($wallet, 'clearing', 'USD', WalletAccountCategory::Clearing);
        foreach ([$wallet, $asset, $clearing] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();

        $source = $this->postingService->post(WalletTransactionType::Credit, 'partial-refund-source', [
            new WalletPostingInstruction($asset, 500),
            new WalletPostingInstruction($clearing, -500),
        ]);
        $this->operations->refundPartial($source, 200, 'partial-refund-1', [
            new WalletPostingInstruction($asset, -200),
            new WalletPostingInstruction($clearing, 200),
        ]);
        $this->operations->refundPartial($source, 300, 'partial-refund-2', [
            new WalletPostingInstruction($asset, -300),
            new WalletPostingInstruction($clearing, 300),
        ]);

        $sum = (int) $this->entityManager->getConnection()->fetchOne("SELECT COALESCE(SUM(amount_minor), 0) FROM financial_operation_link WHERE source_transaction_id = ? AND operation_type = 'refund'", [$source->id()->toRfc4122()]);
        self::assertSame(500, $sum);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Refund exceeds the remaining refundable amount');
        $this->operations->refundPartial($source, 1, 'partial-refund-overflow', [
            new WalletPostingInstruction($asset, -1),
            new WalletPostingInstruction($clearing, 1),
        ]);
    }
}
