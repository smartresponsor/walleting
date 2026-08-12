<?php

declare(strict_types=1);

namespace App\Walleting\Tests\Integration;

use App\Walleting\Entity\Account;
use App\Walleting\Entity\FinancialOperationLink;
use App\Walleting\Entity\Wallet;
use App\Walleting\Enum\AccountCategory;
use App\Walleting\Enum\ReservationStatus;
use App\Walleting\Enum\TransactionType;
use App\Walleting\Ledger\PostingInstruction;
use App\Walleting\Service\FinancialOperationService;
use App\Walleting\Service\NullPostingTelemetry;
use App\Walleting\Service\OutboxService;
use App\Walleting\Service\PostingDbalExecutor;
use App\Walleting\Service\PostingRetryPolicy;
use App\Walleting\Service\PostingService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PostgreSqlPartialFinancialOperationsTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private FinancialOperationService $operations;
    private PostingService $postingService;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        $connection = $this->entityManager->getConnection();
        $outboxService = new OutboxService($this->entityManager, $connection);
        $this->postingService = new PostingService(
            $this->entityManager,
            $outboxService,
            new PostingDbalExecutor($connection, $outboxService, new PostingRetryPolicy(), new NullPostingTelemetry()),
        );
        $this->operations = new FinancialOperationService($this->entityManager, $this->postingService, $outboxService);
        self::assertInstanceOf(\Doctrine\DBAL\Platforms\PostgreSQLPlatform::class, $connection->getDatabasePlatform());
    }

    public function testReservationCanBePartiallyCapturedThenReleasedToMixedSettlement(): void
    {
        $wallet = new Wallet('vendor', 'partial-reservation-wallet');
        $reserved = new Account($wallet, 'reserved', 'USD', AccountCategory::Reserve);
        $clearing = new Account($wallet, 'clearing', 'USD', AccountCategory::Clearing);
        foreach ([$wallet, $reserved, $clearing] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();

        $reservation = $this->operations->reserve($wallet, $reserved, 500, 'USD', 'partial-reserve-1', [
            new PostingInstruction($clearing, -500),
            new PostingInstruction($reserved, 500),
        ]);
        $this->operations->capturePartial($reservation, 200, 'partial-capture-1', [
            new PostingInstruction($reserved, -200),
            new PostingInstruction($clearing, 200),
        ]);
        self::assertSame(ReservationStatus::PartiallySettled, $reservation->status());

        $this->operations->releasePartial($reservation, 300, 'partial-release-1', [
            new PostingInstruction($reserved, -300),
            new PostingInstruction($clearing, 300),
        ]);
        self::assertSame(ReservationStatus::Settled, $reservation->status());

        $links = $this->entityManager->getRepository(FinancialOperationLink::class)->findBy(['reservation' => $reservation], ['amountMinor' => 'ASC']);
        self::assertCount(2, $links);
        self::assertSame([200, 300], array_map(static fn (FinancialOperationLink $link): int => $link->amountMinor(), $links));
    }

    public function testTwoPartialRefundsCanConsumeExactlyTheSourceAmount(): void
    {
        $wallet = new Wallet('vendor', 'partial-refund-wallet');
        $asset = new Account($wallet, 'asset', 'USD', AccountCategory::Asset);
        $clearing = new Account($wallet, 'clearing', 'USD', AccountCategory::Clearing);
        foreach ([$wallet, $asset, $clearing] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();

        $source = $this->postingService->post(TransactionType::Credit, 'partial-refund-source', [
            new PostingInstruction($asset, 500),
            new PostingInstruction($clearing, -500),
        ]);
        $this->operations->refundPartial($source, 200, 'partial-refund-1', [
            new PostingInstruction($asset, -200),
            new PostingInstruction($clearing, 200),
        ]);
        $this->operations->refundPartial($source, 300, 'partial-refund-2', [
            new PostingInstruction($asset, -300),
            new PostingInstruction($clearing, 300),
        ]);

        $sum = (int) $this->entityManager->getConnection()->fetchOne("SELECT COALESCE(SUM(amount_minor), 0) FROM financial_operation_link WHERE source_transaction_id = ? AND operation_type = 'refund'", [$source->id()->toRfc4122()]);
        self::assertSame(500, $sum);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Refund exceeds the remaining refundable amount');
        $this->operations->refundPartial($source, 1, 'partial-refund-overflow', [
            new PostingInstruction($asset, -1),
            new PostingInstruction($clearing, 1),
        ]);
    }
}
