<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Account;
use App\Entity\LedgerTransaction;
use App\Entity\Wallet;
use App\Enum\AccountCategory;
use App\Enum\TransactionStatus;
use App\Enum\TransactionType;
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
}
