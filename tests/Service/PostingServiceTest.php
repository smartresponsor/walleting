<?php

declare(strict_types=1);

namespace App\Walleting\Tests\Service;

use App\Walleting\Entity\Wallet;
use App\Walleting\Entity\WalletAccount;
use App\Walleting\Enum\WalletAccountCategory;
use App\Walleting\Service\WalletOutboxService;
use App\Walleting\Service\WalletPostingDbalExecutor;
use App\Walleting\Service\WalletPostingService;
use App\Walleting\ValueObject\Ledger\WalletPostingInstruction;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class PostingServiceTest extends TestCase
{
    public function testUnbalancedInstructionsAreRejected(): void
    {
        $service = $this->service();
        $wallet = new Wallet('vendor', 'vendor-1');
        $cash = new WalletAccount($wallet, 'cash', 'USD', WalletAccountCategory::Asset);
        $clearing = new WalletAccount($wallet, 'clearing', 'USD', WalletAccountCategory::Clearing);

        $this->expectException(\InvalidArgumentException::class);
        $service->credit('credit-unbalanced', [
            new WalletPostingInstruction($cash, 1000),
            new WalletPostingInstruction($clearing, -900),
        ]);
    }

    public function testCrossCurrencyInstructionsAreRejected(): void
    {
        $service = $this->service();
        $wallet = new Wallet('vendor', 'vendor-1');
        $usd = new WalletAccount($wallet, 'cash-usd', 'USD', WalletAccountCategory::Asset);
        $eur = new WalletAccount($wallet, 'cash-eur', 'EUR', WalletAccountCategory::Asset);

        $this->expectException(\InvalidArgumentException::class);
        $service->transfer('transfer-cross-currency', [
            new WalletPostingInstruction($usd, -1000),
            new WalletPostingInstruction($eur, 1000),
        ]);
    }

    public function testZeroInstructionIsRejected(): void
    {
        $wallet = new Wallet('vendor', 'vendor-1');
        $account = new WalletAccount($wallet, 'cash', 'USD', WalletAccountCategory::Asset);

        $this->expectException(\InvalidArgumentException::class);
        new WalletPostingInstruction($account, 0);
    }

    private function service(): WalletPostingService
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $connection = $this->createStub(Connection::class);
        $outboxService = new WalletOutboxService($entityManager, $connection);

        return new WalletPostingService(
            $entityManager,
            $outboxService,
            new WalletPostingDbalExecutor($connection, $outboxService, new \App\Walleting\Policy\Posting\WalletPostingRetryPolicy(), new \App\Walleting\Service\WalletNullPostingTelemetry()),
        );
    }
}
