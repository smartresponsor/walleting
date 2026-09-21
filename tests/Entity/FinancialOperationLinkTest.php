<?php

declare(strict_types=1);

namespace App\Walleting\Tests\Entity;

use App\Walleting\Entity\Wallet;
use App\Walleting\Entity\WalletAccount;
use App\Walleting\Entity\WalletFinancialOperationLink;
use App\Walleting\Entity\WalletLedgerTransaction;
use App\Walleting\Entity\WalletReservation;
use App\Walleting\Enum\WalletAccountCategory;
use App\Walleting\Enum\WalletTransactionType;
use PHPUnit\Framework\TestCase;

final class FinancialOperationLinkTest extends TestCase
{
    public function testCaptureRequiresMatchingReservationSource(): void
    {
        $wallet = new Wallet('vendor', 'vendor-1');
        $account = new WalletAccount($wallet, 'reserve', 'USD', WalletAccountCategory::Reserve);
        $reserve = new WalletLedgerTransaction(WalletTransactionType::Reserve, 'reserve-link-1');
        $capture = new WalletLedgerTransaction(WalletTransactionType::Capture, 'capture-link-1');
        $reservation = new WalletReservation($wallet, $account, $reserve, 500, 'USD', 'reservation-link-1');

        $link = new WalletFinancialOperationLink(WalletTransactionType::Capture, $reserve, $capture, 500, $reservation);

        self::assertSame($reserve, $link->sourceTransaction());
        self::assertSame($capture, $link->resultTransaction());
        self::assertSame($reservation, $link->reservation());
    }

    public function testResultTransactionTypeMustMatchLinkedOperation(): void
    {
        $source = new WalletLedgerTransaction(WalletTransactionType::Credit, 'source-result-mismatch');
        $result = new WalletLedgerTransaction(WalletTransactionType::Reverse, 'result-result-mismatch');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Linked result transaction type must match the operation type.');
        new WalletFinancialOperationLink(WalletTransactionType::Refund, $source, $result, 500);
    }

    public function testInverseOperationRejectsInverseSourceTransaction(): void
    {
        $source = new WalletLedgerTransaction(WalletTransactionType::Refund, 'source-inverse');
        $result = new WalletLedgerTransaction(WalletTransactionType::Reverse, 'result-inverse');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Refund and reverse cannot originate from an inverse transaction.');
        new WalletFinancialOperationLink(WalletTransactionType::Reverse, $source, $result, 500);
    }

    public function testRefundRejectsReservationAssociation(): void
    {
        $wallet = new Wallet('vendor', 'vendor-1');
        $account = new WalletAccount($wallet, 'reserve', 'USD', WalletAccountCategory::Reserve);
        $source = new WalletLedgerTransaction(WalletTransactionType::Credit, 'credit-link-1');
        $result = new WalletLedgerTransaction(WalletTransactionType::Refund, 'refund-link-1');
        $reservation = new WalletReservation($wallet, $account, $source, 500, 'USD', 'reservation-link-2');

        $this->expectException(\InvalidArgumentException::class);
        new WalletFinancialOperationLink(WalletTransactionType::Refund, $source, $result, 500, $reservation);
    }
}
