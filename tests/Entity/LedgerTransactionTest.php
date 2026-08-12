<?php

declare(strict_types=1);

namespace App\Walleting\Tests\Entity;

use App\Walleting\Entity\Account;
use App\Walleting\Entity\LedgerTransaction;
use App\Walleting\Entity\Wallet;
use App\Walleting\Enum\AccountCategory;
use App\Walleting\Enum\TransactionStatus;
use App\Walleting\Enum\TransactionType;
use PHPUnit\Framework\TestCase;

final class LedgerTransactionTest extends TestCase
{
    public function testBalancedTransactionCanBePosted(): void
    {
        $wallet = new Wallet('vendor', 'vendor-1');
        $cash = new Account($wallet, 'cash', 'USD', AccountCategory::Asset);
        $clearing = new Account($wallet, 'clearing', 'USD', AccountCategory::Clearing);
        $transaction = new LedgerTransaction(TransactionType::Credit, 'credit-1');

        $transaction->addPosting($cash, 1250);
        $transaction->addPosting($clearing, -1250);
        $transaction->post();

        self::assertSame(TransactionStatus::Posted, $transaction->status());
        self::assertNotNull($transaction->postedAt());
    }

    public function testUnbalancedTransactionCannotBePosted(): void
    {
        $wallet = new Wallet('vendor', 'vendor-1');
        $cash = new Account($wallet, 'cash', 'USD', AccountCategory::Asset);
        $clearing = new Account($wallet, 'clearing', 'USD', AccountCategory::Clearing);
        $transaction = new LedgerTransaction(TransactionType::Credit, 'credit-2');
        $transaction->addPosting($cash, 1250);
        $transaction->addPosting($clearing, -1200);

        $this->expectException(\LogicException::class);
        $transaction->post();
    }

    public function testPostingAmountCannotBeZero(): void
    {
        $wallet = new Wallet('vendor', 'vendor-1');
        $account = new Account($wallet, 'cash', 'USD', AccountCategory::Asset);
        $transaction = new LedgerTransaction(TransactionType::Debit, 'debit-1');

        $this->expectException(\InvalidArgumentException::class);
        $transaction->addPosting($account, 0);
    }

    public function testPostingsReceiveStableSequenceAndCurrencySnapshot(): void
    {
        $wallet = new Wallet('vendor', 'vendor-1');
        $cash = new Account($wallet, 'cash', 'USD', AccountCategory::Asset);
        $clearing = new Account($wallet, 'clearing', 'USD', AccountCategory::Clearing);
        $transaction = new LedgerTransaction(TransactionType::Credit, 'credit-sequence-1');

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
        $usd = new Account($wallet, 'cash-usd', 'USD', AccountCategory::Asset);
        $eur = new Account($wallet, 'cash-eur', 'EUR', AccountCategory::Asset);
        $transaction = new LedgerTransaction(TransactionType::Transfer, 'transfer-cross-currency-entity');
        $transaction->addPosting($usd, -1000);

        $this->expectException(\InvalidArgumentException::class);
        $transaction->addPosting($eur, 1000);
    }
}
