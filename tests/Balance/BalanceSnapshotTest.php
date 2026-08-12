<?php

declare(strict_types=1);

namespace App\Walleting\Tests\Balance;

use App\Walleting\Balance\AccountBalanceReconciliation;
use App\Walleting\Balance\WalletCurrencyBalanceSnapshot;
use PHPUnit\Framework\TestCase;

final class BalanceSnapshotTest extends TestCase
{
    public function testWalletSnapshotExposesAvailableReservedAndTotal(): void
    {
        $snapshot = new WalletCurrencyBalanceSnapshot('USD', 1250, 500);

        self::assertSame(1250, $snapshot->availableMinor);
        self::assertSame(500, $snapshot->reservedMinor);
        self::assertSame(1750, $snapshot->totalMinor());
    }

    public function testReconciliationRequiresBalanceAndPostingCountAgreement(): void
    {
        $consistent = new AccountBalanceReconciliation('account-1', 'USD', 1250, 1250, 3, 3);
        $balanceMismatch = new AccountBalanceReconciliation('account-1', 'USD', 1200, 1250, 3, 3);
        $countMismatch = new AccountBalanceReconciliation('account-1', 'USD', 1250, 1250, 2, 3);

        self::assertTrue($consistent->isConsistent());
        self::assertFalse($balanceMismatch->isConsistent());
        self::assertFalse($countMismatch->isConsistent());
    }
}
