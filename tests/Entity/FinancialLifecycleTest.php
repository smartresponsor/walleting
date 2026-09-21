<?php

declare(strict_types=1);

namespace App\Walleting\Tests\Entity;

use App\Walleting\Entity\Wallet;
use App\Walleting\Entity\WalletAccount;
use App\Walleting\Entity\WalletFunding;
use App\Walleting\Entity\WalletLedgerTransaction;
use App\Walleting\Entity\WalletPaymentInstrument;
use App\Walleting\Entity\WalletReservation;
use App\Walleting\Entity\WalletWithdrawal;
use App\Walleting\Enum\WalletAccountCategory;
use App\Walleting\Enum\WalletFundingStatus;
use App\Walleting\Enum\WalletPaymentInstrumentStatus;
use App\Walleting\Enum\WalletPaymentInstrumentType;
use App\Walleting\Enum\WalletReservationStatus;
use App\Walleting\Enum\WalletTransactionType;
use App\Walleting\Enum\WalletWithdrawalStatus;
use PHPUnit\Framework\TestCase;

final class FinancialLifecycleTest extends TestCase
{
    public function testWalletCreatedAtUsesCanonicalObjectingAuditSurface(): void
    {
        $wallet = new Wallet('vendor', 'created-at');

        self::assertSame($wallet->getCreatedAt(), $wallet->createdAt());
    }

    public function testPaymentInstrumentCanBeDisabled(): void
    {
        $instrument = new WalletPaymentInstrument(new Wallet('vendor', '1'), WalletPaymentInstrumentType::Card, 'stripe', 'pm_1', 'Visa 4242');
        $instrument->disable();
        self::assertSame(WalletPaymentInstrumentStatus::Disabled, $instrument->status());
    }

    public function testReservationCanOnlyTransitionOnce(): void
    {
        $wallet = new Wallet('vendor', '1');
        $account = new WalletAccount($wallet, 'reserve', 'USD', WalletAccountCategory::Reserve);
        $reservation = new WalletReservation($wallet, $account, new WalletLedgerTransaction(WalletTransactionType::Reserve, 'reserve-1'), 500, 'USD', 'reservation-1');
        $reservation->capture();
        self::assertSame(WalletReservationStatus::Captured, $reservation->status());

        $this->expectException(\LogicException::class);
        $reservation->release();
    }

    public function testReservationCurrencyMustMatchAccountCurrency(): void
    {
        $wallet = new Wallet('vendor', 'reservation-currency');
        $account = new WalletAccount($wallet, 'reserve', 'EUR', WalletAccountCategory::Reserve);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Reservation currency must match the account currency.');

        new WalletReservation($wallet, $account, new WalletLedgerTransaction(WalletTransactionType::Reserve, 'reserve-currency'), 500, 'USD', 'reservation-currency');
    }

    public function testFundingMustProcessBeforeSuccess(): void
    {
        $wallet = new Wallet('vendor', '1');
        $instrument = new WalletPaymentInstrument($wallet, WalletPaymentInstrumentType::Card, 'stripe', 'pm_2', 'Visa 4242');
        $funding = new WalletFunding($wallet, $instrument, 1000, 'USD', 'funding-1');
        self::assertSame(WalletFundingStatus::Pending, $funding->status());

        $funding->start();
        $funding->bindProviderOperationReference('ch_funding_1');
        $funding->bindProviderOperationReference('ch_funding_1');
        self::assertSame('ch_funding_1', $funding->providerOperationReference());
        $transaction = new WalletLedgerTransaction(WalletTransactionType::Credit, 'credit-funding-1');
        $funding->succeed($transaction);
        self::assertSame(WalletFundingStatus::Succeeded, $funding->status());
        self::assertSame($transaction, $funding->transaction());
    }

    public function testFundingCannotSucceedBeforeProviderProcessing(): void
    {
        $wallet = new Wallet('vendor', 'funding-direct-success');
        $instrument = new WalletPaymentInstrument($wallet, WalletPaymentInstrumentType::Card, 'stripe', 'pm_direct', 'Visa');
        $funding = new WalletFunding($wallet, $instrument, 1000, 'USD', 'funding-direct-success');

        $this->expectException(\LogicException::class);
        $funding->succeed(new WalletLedgerTransaction(WalletTransactionType::Credit, 'credit-direct-success'));
    }

    public function testFundingProviderOperationReferenceCannotBeRebound(): void
    {
        $wallet = new Wallet('vendor', 'funding-provider-reference-conflict');
        $instrument = new WalletPaymentInstrument($wallet, WalletPaymentInstrumentType::Card, 'stripe', 'pm_provider_reference_conflict', 'Card');
        $funding = new WalletFunding($wallet, $instrument, 1000, 'USD', 'funding-provider-reference-conflict');
        $funding->start();
        $funding->bindProviderOperationReference('ch_original');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Funding is already bound to a different provider operation reference.');
        $funding->bindProviderOperationReference('ch_different');
    }

    public function testFundingRequiresActivePaymentInstrument(): void
    {
        $wallet = new Wallet('vendor', 'funding-disabled-instrument');
        $instrument = new WalletPaymentInstrument($wallet, WalletPaymentInstrumentType::Card, 'stripe', 'pm_disabled', 'Disabled card');
        $instrument->disable();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Funding instrument must be active.');

        new WalletFunding($wallet, $instrument, 1000, 'USD', 'funding-disabled-instrument');
    }

    public function testWithdrawalMustProcessBeforeSuccess(): void
    {
        $wallet = new Wallet('vendor', '1');
        $instrument = new WalletPaymentInstrument($wallet, WalletPaymentInstrumentType::BankAccount, 'stripe', 'ba_1', 'Bank 6789');
        $withdrawal = new WalletWithdrawal($wallet, $instrument, 700, 'USD', 'withdrawal-1');
        self::assertSame(WalletWithdrawalStatus::Pending, $withdrawal->status());

        $withdrawal->start();
        $withdrawal->bindProviderOperationReference('po_withdrawal_1');
        $withdrawal->bindProviderOperationReference('po_withdrawal_1');
        self::assertSame('po_withdrawal_1', $withdrawal->providerOperationReference());
        $withdrawal->succeed(new WalletLedgerTransaction(WalletTransactionType::Debit, 'debit-withdrawal-1'));
        self::assertSame(WalletWithdrawalStatus::Succeeded, $withdrawal->status());
    }

    public function testWithdrawalRequiresActivePaymentInstrument(): void
    {
        $wallet = new Wallet('vendor', 'withdrawal-expired-instrument');
        $instrument = new WalletPaymentInstrument($wallet, WalletPaymentInstrumentType::BankAccount, 'ach', 'ba_expired', 'Expired bank');
        $instrument->expire();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Withdrawal instrument must be active.');

        new WalletWithdrawal($wallet, $instrument, 700, 'USD', 'withdrawal-expired-instrument');
    }
}
