<?php

declare(strict_types=1);

namespace App\Walleting\Tests\Integration;

use App\Walleting\Entity\Wallet;
use App\Walleting\Entity\WalletAccount;
use App\Walleting\Enum\WalletAccountCategory;
use App\Walleting\Enum\WalletReservationStatus;
use App\Walleting\Policy\Posting\WalletPostingRetryPolicy;
use App\Walleting\Service\WalletBalanceReadService;
use App\Walleting\Service\WalletFinancialOperationService;
use App\Walleting\Service\WalletingFacade;
use App\Walleting\Service\WalletLedgerQueryService;
use App\Walleting\Service\WalletNullPostingTelemetry;
use App\Walleting\Service\WalletOutboxService;
use App\Walleting\Service\WalletPostingDbalExecutor;
use App\Walleting\Service\WalletPostingService;
use App\Walleting\Service\WalletStatementQueryService;
use App\Walleting\ValueObject\Ledger\WalletPostingInstruction;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PostgreSqlWalletingFacadeTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private WalletingFacade $facade;
    private WalletFinancialOperationService $operations;
    private WalletPostingService $postingService;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        $connection = $this->entityManager->getConnection();
        $outbox = new WalletOutboxService($this->entityManager, $connection);
        $this->postingService = new WalletPostingService($this->entityManager, $outbox, new WalletPostingDbalExecutor($connection, $outbox, new WalletPostingRetryPolicy(), new WalletNullPostingTelemetry()));
        $this->operations = new WalletFinancialOperationService($this->entityManager, $this->postingService, $outbox);
        $this->facade = new WalletingFacade(new WalletBalanceReadService($connection), new WalletLedgerQueryService($connection), new WalletStatementQueryService($connection), $connection);
    }

    public function testFacadeExposesBalanceHistoryStatementAndReservationProgress(): void
    {
        $wallet = new Wallet('vendor', 'facade-integration-wallet');
        $asset = new WalletAccount($wallet, 'asset', 'USD', WalletAccountCategory::Asset);
        $reserve = new WalletAccount($wallet, 'reserve', 'USD', WalletAccountCategory::Reserve);
        $clearing = new WalletAccount($wallet, 'clearing', 'USD', WalletAccountCategory::Clearing);
        foreach ([$wallet, $asset, $reserve, $clearing] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();

        $this->postingService->credit('facade-credit-1', [
            new WalletPostingInstruction($asset, 1000),
            new WalletPostingInstruction($clearing, -1000),
        ]);
        $reservation = $this->operations->reserve($wallet, $reserve, 600, 'USD', 'facade-reserve-1', [
            new WalletPostingInstruction($asset, -600),
            new WalletPostingInstruction($reserve, 600),
        ]);
        $this->operations->capturePartial($reservation, 200, 'facade-capture-1', [
            new WalletPostingInstruction($reserve, -200),
            new WalletPostingInstruction($clearing, 200),
        ]);

        $balance = $this->facade->walletBalance($wallet);
        self::assertSame($wallet->id()->toRfc4122(), $balance->walletId);
        self::assertCount(1, $balance->currencies);
        self::assertSame('USD', $balance->currencies[0]->currency);
        self::assertSame(400, $balance->currencies[0]->availableMinor);
        self::assertSame(400, $balance->currencies[0]->reservedMinor);

        $reservationView = $this->facade->reservation($reservation);
        self::assertSame(WalletReservationStatus::PartiallySettled->value, $reservationView->status);
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
