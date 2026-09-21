<?php

declare(strict_types=1);

namespace App\Walleting\Tests\Service;

use App\Walleting\Entity\Wallet;
use App\Walleting\Entity\WalletAccount;
use App\Walleting\Enum\WalletAccountCategory;
use App\Walleting\Service\WalletBalanceQueryService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class BalanceQueryServiceTest extends TestCase
{
    public function testWalletSnapshotSeparatesAvailableAndReservedBalances(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchAssociative')
            ->with(self::stringContains('account_balance'), self::callback(static fn (array $parameters): bool => 'USD' === $parameters[1]))
            ->willReturn([
                'available_minor' => '1250',
                'reserved_minor' => '350',
                'posting_count' => '7',
                'updated_at' => '2026-08-02 01:30:00',
            ]);

        $snapshot = (new WalletBalanceQueryService($connection))->wallet(new Wallet('vendor', 'balance-query'), 'usd');

        self::assertSame('USD', $snapshot->currency);
        self::assertSame(1600, $snapshot->ledgerMinor);
        self::assertSame(1250, $snapshot->availableMinor);
        self::assertSame(350, $snapshot->reservedMinor);
        self::assertSame(7, $snapshot->postingCount);
        self::assertFalse($snapshot->isEmpty());
    }

    public function testAccountWithoutProjectionReturnsZero(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchOne')
            ->willReturn(false);

        $wallet = new Wallet('vendor', 'empty-account');
        $account = new WalletAccount($wallet, 'available', 'USD', WalletAccountCategory::Asset);

        self::assertSame(0, (new WalletBalanceQueryService($connection))->account($account));
    }

    public function testSnapshotRejectsInvalidCurrency(): void
    {
        $connection = $this->createStub(Connection::class);

        $this->expectException(\InvalidArgumentException::class);
        (new WalletBalanceQueryService($connection))->wallet(new Wallet('vendor', 'bad-currency'), 'US');
    }
}
