<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Account;
use App\Entity\Funding;
use App\Entity\LedgerTransaction;
use App\Entity\PaymentInstrument;
use App\Entity\Reservation;
use App\Entity\Wallet;
use App\Entity\Withdrawal;
use App\Enum\AccountCategory;
use App\Enum\FundingStatus;
use App\Enum\PaymentInstrumentStatus;
use App\Enum\PaymentInstrumentType;
use App\Enum\ReservationStatus;
use App\Enum\TransactionType;
use App\Enum\WithdrawalStatus;
use PHPUnit\Framework\TestCase;

final class FinancialLifecycleTest extends TestCase
{
    public function testPaymentInstrumentCanBeDisabled(): void
    {
        $instrument = new PaymentInstrument(new Wallet('vendor', '1'), PaymentInstrumentType::Card, 'stripe', 'pm_1', 'Visa 4242');
        $instrument->disable();
        self::assertSame(PaymentInstrumentStatus::Disabled, $instrument->status());
    }

    public function testReservationCanOnlyTransitionOnce(): void
    {
        $wallet = new Wallet('vendor', '1');
        $account = new Account($wallet, 'reserve', 'USD', AccountCategory::Reserve);
        $reservation = new Reservation($wallet, $account, new LedgerTransaction(TransactionType::Reserve, 'reserve-1'), 500, 'USD', 'reservation-1');
        $reservation->capture();
        self::assertSame(ReservationStatus::Captured, $reservation->status());

        $this->expectException(\LogicException::class);
        $reservation->release();
    }

    public function testFundingSuccessRequiresPendingState(): void
    {
        $wallet = new Wallet('vendor', '1');
        $instrument = new PaymentInstrument($wallet, PaymentInstrumentType::Card, 'stripe', 'pm_2', 'Visa 4242');
        $funding = new Funding($wallet, $instrument, 1000, 'USD', 'funding-1');
        $transaction = new LedgerTransaction(TransactionType::Credit, 'credit-funding-1');
        $funding->succeed($transaction);
        self::assertSame(FundingStatus::Succeeded, $funding->status());
        self::assertSame($transaction, $funding->transaction());
    }

    public function testWithdrawalMustProcessBeforeSuccess(): void
    {
        $wallet = new Wallet('vendor', '1');
        $instrument = new PaymentInstrument($wallet, PaymentInstrumentType::BankAccount, 'stripe', 'ba_1', 'Bank 6789');
        $withdrawal = new Withdrawal($wallet, $instrument, 700, 'USD', 'withdrawal-1');
        self::assertSame(WithdrawalStatus::Pending, $withdrawal->status());

        $withdrawal->start();
        $withdrawal->succeed(new LedgerTransaction(TransactionType::Debit, 'debit-withdrawal-1'));
        self::assertSame(WithdrawalStatus::Succeeded, $withdrawal->status());
    }
}
