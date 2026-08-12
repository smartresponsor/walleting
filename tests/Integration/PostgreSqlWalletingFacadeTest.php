<?php

declare(strict_types=1);

namespace App\Walleting\Tests\Integration;

use App\Walleting\Entity\Account;
use App\Walleting\Entity\Wallet;
use App\Walleting\Enum\AccountCategory;
use App\Walleting\Enum\ReservationStatus;
use App\Walleting\Ledger\PostingInstruction;
use App\Walleting\Service\BalanceReadService;
use App\Walleting\Service\FinancialOperationService;
use App\Walleting\Service\LedgerQueryService;
use App\Walleting\Service\NullPostingTelemetry;
use App\Walleting\Service\OutboxService;
use App\Walleting\Service\PostingDbalExecutor;
use App\Walleting\Service\PostingRetryPolicy;
use App\Walleting\Service\PostingService;
use App\Walleting\Service\StatementQueryService;
use App\Walleting\Service\WalletingFacade;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PostgreSqlWalletingFacadeTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private WalletingFacade $facade;
    private FinancialOperationService $operations;
    private PostingService $postingService;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        $connection = $this->entityManager->getConnection();
        $outbox = new OutboxService($this->entityManager, $connection);
        $this->postingService = new PostingService($this->entityManager, $outbox, new PostingDbalExecutor($connection, $outbox, new PostingRetryPolicy(), new NullPostingTelemetry()));
        $this->operations = new FinancialOperationService($this->entityManager, $this->postingService, $outbox);
        $this->facade = new WalletingFacade(new BalanceReadService($connection), new LedgerQueryService($connection), new StatementQueryService($connection), $connection);
    }

    public function testFacadeExposesBalanceHistoryStatementAndReservationProgress(): void
    {
        $wallet = new Wallet('vendor', 'facade-integration-wallet');
        $asset = new Account($wallet, 'asset', 'USD', AccountCategory::Asset);
        $reserve = new Account($wallet, 'reserve', 'USD', AccountCategory::Reserve);
        $clearing = new Account($wallet, 'clearing', 'USD', AccountCategory::Clearing);
        foreach ([$wallet, $asset, $reserve, $clearing] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();

        $this->postingService->credit('facade-credit-1', [
            new PostingInstruction($asset, 1000),
            new PostingInstruction($clearing, -1000),
        ]);
        $reservation = $this->operations->reserve($wallet, $reserve, 600, 'USD', 'facade-reserve-1', [
            new PostingInstruction($asset, -600),
            new PostingInstruction($reserve, 600),
        ]);
        $this->operations->capturePartial($reservation, 200, 'facade-capture-1', [
            new PostingInstruction($reserve, -200),
            new PostingInstruction($clearing, 200),
        ]);

        $balance = $this->facade->walletBalance($wallet);
        self::assertSame($wallet->id()->toRfc4122(), $balance->walletId);
        self::assertCount(1, $balance->currencies);
        self::assertSame('USD', $balance->currencies[0]->currency);
        self::assertSame(400, $balance->currencies[0]->availableMinor);
        self::assertSame(400, $balance->currencies[0]->reservedMinor);

        $reservationView = $this->facade->reservation($reservation);
        self::assertSame(ReservationStatus::PartiallySettled->value, $reservationView->status);
        self::assertSame(600, $reservationView->amountMinor);
        self::assertSame(200, $reservationView->capturedMinor);
        self::assertSame(0, $reservationView->releasedMinor);
        self::assertSame(400, $reservationView->remainingMinor);

        $history = $this->facade->accountHistory($reserve);
        self::assertCount(2, $history->items);
        self::assertSame(-200, $history->items[0]->amountMinor);
        self::assertSame(600, $history->items[1]->amountMinor);

        $statement = $this->facade->accountStatement($reserve);
        self::assertCount(2, $statement->items);
        self::assertSame(-200, $statement->items[0]->amountMinor);
        self::assertSame(400, $statement->items[0]->runningBalanceMinor);
    }
}
