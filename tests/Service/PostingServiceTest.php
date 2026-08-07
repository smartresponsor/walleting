<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Account;
use App\Entity\Wallet;
use App\Enum\AccountCategory;
use App\Ledger\PostingInstruction;
use App\Service\OutboxService;
use App\Service\PostingDbalExecutor;
use App\Service\PostingService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class PostingServiceTest extends TestCase
{
    public function testUnbalancedInstructionsAreRejected(): void
    {
        $service = $this->service();
        $wallet = new Wallet('vendor', 'vendor-1');
        $cash = new Account($wallet, 'cash', 'USD', AccountCategory::Asset);
        $clearing = new Account($wallet, 'clearing', 'USD', AccountCategory::Clearing);

        $this->expectException(\InvalidArgumentException::class);
        $service->credit('credit-unbalanced', [
            new PostingInstruction($cash, 1000),
            new PostingInstruction($clearing, -900),
        ]);
    }

    public function testCrossCurrencyInstructionsAreRejected(): void
    {
        $service = $this->service();
        $wallet = new Wallet('vendor', 'vendor-1');
        $usd = new Account($wallet, 'cash-usd', 'USD', AccountCategory::Asset);
        $eur = new Account($wallet, 'cash-eur', 'EUR', AccountCategory::Asset);

        $this->expectException(\InvalidArgumentException::class);
        $service->transfer('transfer-cross-currency', [
            new PostingInstruction($usd, -1000),
            new PostingInstruction($eur, 1000),
        ]);
    }

    public function testZeroInstructionIsRejected(): void
    {
        $wallet = new Wallet('vendor', 'vendor-1');
        $account = new Account($wallet, 'cash', 'USD', AccountCategory::Asset);

        $this->expectException(\InvalidArgumentException::class);
        new PostingInstruction($account, 0);
    }

    private function service(): PostingService
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $connection = $this->createStub(Connection::class);
        $outboxService = new OutboxService($entityManager, $connection);

        return new PostingService(
            $entityManager,
            $outboxService,
            new PostingDbalExecutor($connection, $outboxService),
        );
    }
}
