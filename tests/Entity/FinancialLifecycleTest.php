<?php

declare(strict_types=1);

namespace App\Walleting\Tests\Entity;

use App\Walleting\Entity\Account;
use App\Walleting\Entity\Funding;
use App\Walleting\Entity\LedgerTransaction;
use App\Walleting\Entity\PaymentInstrument;
use App\Walleting\Entity\Reservation;
use App\Walleting\Entity\Wallet;
use App\Walleting\Entity\Withdrawal;
use App\Walleting\Enum\AccountCategory;
use App\Walleting\Enum\FundingStatus;
use App\Walleting\Enum\PaymentInstrumentStatus;
use App\Walleting\Enum\PaymentInstrumentType;
use App\Walleting\Enum\ReservationStatus;
use App\Walleting\Enum\TransactionType;
use App\Walleting\Enum\WithdrawalStatus;
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

    public function testReservationCurrencyMustMatchAccountCurrency(): void
    {
        $wallet = new Wallet('vendor', 'reservation-currency');
        $account = new Account($wallet, 'reserve', 'EUR', AccountCategory::Reserve);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Reservation currency must match the account currency.');

        new Reservation($wallet, $account, new LedgerTransaction(TransactionType::Reserve, 'reserve-currency'), 500, 'USD', 'reservation-currency');
    }

    public function testFundingMustProcessBeforeSuccess(): void
    {
        $wallet = new Wallet('vendor', '1');
        $instrument = new PaymentInstrument($wallet, PaymentInstrumentType::Card, 'stripe', 'pm_2', 'Visa 4242');
        $funding = new Funding($wallet, $instrument, 1000, 'USD', 'funding-1');
        self::assertSame(FundingStatus::Pending, $funding->status());

        $funding->start();
        $transaction = new LedgerTransaction(TransactionType::Credit, 'credit-funding-1');
        $funding->succeed($transaction);
        self::assertSame(FundingStatus::Succeeded, $funding->status());
        self::assertSame($transaction, $funding->transaction());
    }

    public function testFundingCannotSucceedBeforeProviderProcessing(): void
    {
        $wallet = new Wallet('vendor', 'funding-direct-success');
        $instrument = new PaymentInstrument($wallet, PaymentInstrumentType::Card, 'stripe', 'pm_direct', 'Visa');
        $funding = new Funding($wallet, $instrument, 1000, 'USD', 'funding-direct-success');

        $this->expectException(\LogicException::class);
        $funding->succeed(new LedgerTransaction(TransactionType::Credit, 'credit-direct-success'));
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
