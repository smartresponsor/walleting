<?php

declare(strict_types=1);

namespace App\Walleting\Tests\Entity;

use App\Walleting\Entity\Wallet;
use App\Walleting\Entity\WalletAccount;
use App\Walleting\Entity\WalletLedgerTransaction;
use App\Walleting\Enum\WalletAccountCategory;
use App\Walleting\Enum\WalletTransactionStatus;
use App\Walleting\Enum\WalletTransactionType;
use PHPUnit\Framework\TestCase;

final class LedgerTransactionTest extends TestCase
{
    public function testBalancedTransactionCanBePosted(): void
    {
        $wallet = new Wallet('vendor', 'vendor-1');
        $cash = new WalletAccount($wallet, 'cash', 'USD', WalletAccountCategory::Asset);
        $clearing = new WalletAccount($wallet, 'clearing', 'USD', WalletAccountCategory::Clearing);
        $transaction = new WalletLedgerTransaction(WalletTransactionType::Credit, 'credit-1');

        $transaction->addPosting($cash, 1250);
        $transaction->addPosting($clearing, -1250);
        $transaction->post();

        self::assertSame(WalletTransactionStatus::Posted, $transaction->status());
        self::assertNotNull($transaction->postedAt());
    }

    public function testUnbalancedTransactionCannotBePosted(): void
    {
        $wallet = new Wallet('vendor', 'vendor-1');
        $cash = new WalletAccount($wallet, 'cash', 'USD', WalletAccountCategory::Asset);
        $clearing = new WalletAccount($wallet, 'clearing', 'USD', WalletAccountCategory::Clearing);
        $transaction = new WalletLedgerTransaction(WalletTransactionType::Credit, 'credit-2');
        $transaction->addPosting($cash, 1250);
        $transaction->addPosting($clearing, -1200);

        $this->expectException(\LogicException::class);
        $transaction->post();
    }

    public function testPostingAmountCannotBeZero(): void
    {
        $wallet = new Wallet('vendor', 'vendor-1');
        $account = new WalletAccount($wallet, 'cash', 'USD', WalletAccountCategory::Asset);
        $transaction = new WalletLedgerTransaction(WalletTransactionType::Debit, 'debit-1');

        $this->expectException(\InvalidArgumentException::class);
        $transaction->addPosting($account, 0);
    }

    public function testPostingsReceiveStableSequenceAndCurrencySnapshot(): void
    {
        $wallet = new Wallet('vendor', 'vendor-1');
        $cash = new WalletAccount($wallet, 'cash', 'USD', WalletAccountCategory::Asset);
        $clearing = new WalletAccount($wallet, 'clearing', 'USD', WalletAccountCategory::Clearing);
        $transaction = new WalletLedgerTransaction(WalletTransactionType::Credit, 'credit-sequence-1');

        $first = $transaction->addPosting($cash, 1250);
        $second = $transaction->addPosting($clearing, -1250);

        self::assertSame(1, $first->sequence());
        self::assertSame(2, $second->sequence());
        self::assertSame('USD', $first->currency());
        self::assertSame('USD', $second->currency());
    }

    public function testTransactionRejectsMultipleCurrencies(): void
    {
        $wallet = new Wallet('vendor', 'vendor-1');
        $usd = new WalletAccount($wallet, 'cash-usd', 'USD', WalletAccountCategory::Asset);
        $eur = new WalletAccount($wallet, 'cash-eur', 'EUR', WalletAccountCategory::Asset);
        $transaction = new WalletLedgerTransaction(WalletTransactionType::Transfer, 'transfer-cross-currency-entity');
        $transaction->addPosting($usd, -1000);

        $this->expectException(\InvalidArgumentException::class);
        $transaction->addPosting($eur, 1000);
    }
}
