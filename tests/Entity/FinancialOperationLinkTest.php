<?php

declare(strict_types=1);

namespace App\Walleting\Tests\Entity;

use App\Walleting\Entity\Account;
use App\Walleting\Entity\FinancialOperationLink;
use App\Walleting\Entity\LedgerTransaction;
use App\Walleting\Entity\Reservation;
use App\Walleting\Entity\Wallet;
use App\Walleting\Enum\AccountCategory;
use App\Walleting\Enum\TransactionType;
use PHPUnit\Framework\TestCase;

final class FinancialOperationLinkTest extends TestCase
{
    public function testCaptureRequiresMatchingReservationSource(): void
    {
        $wallet = new Wallet('vendor', 'vendor-1');
        $account = new Account($wallet, 'reserve', 'USD', AccountCategory::Reserve);
        $reserve = new LedgerTransaction(TransactionType::Reserve, 'reserve-link-1');
        $capture = new LedgerTransaction(TransactionType::Capture, 'capture-link-1');
        $reservation = new Reservation($wallet, $account, $reserve, 500, 'USD', 'reservation-link-1');

        $link = new FinancialOperationLink(TransactionType::Capture, $reserve, $capture, 500, $reservation);

        self::assertSame($reserve, $link->sourceTransaction());
        self::assertSame($capture, $link->resultTransaction());
        self::assertSame($reservation, $link->reservation());
    }

    public function testResultTransactionTypeMustMatchLinkedOperation(): void
    {
        $source = new LedgerTransaction(TransactionType::Credit, 'source-result-mismatch');
        $result = new LedgerTransaction(TransactionType::Reverse, 'result-result-mismatch');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Linked result transaction type must match the operation type.');
        new FinancialOperationLink(TransactionType::Refund, $source, $result, 500);
    }

    public function testInverseOperationRejectsInverseSourceTransaction(): void
    {
        $source = new LedgerTransaction(TransactionType::Refund, 'source-inverse');
        $result = new LedgerTransaction(TransactionType::Reverse, 'result-inverse');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Refund and reverse cannot originate from an inverse transaction.');
        new FinancialOperationLink(TransactionType::Reverse, $source, $result, 500);
    }

    public function testRefundRejectsReservationAssociation(): void
    {
        $wallet = new Wallet('vendor', 'vendor-1');
        $account = new Account($wallet, 'reserve', 'USD', AccountCategory::Reserve);
        $source = new LedgerTransaction(TransactionType::Credit, 'credit-link-1');
        $result = new LedgerTransaction(TransactionType::Refund, 'refund-link-1');
        $reservation = new Reservation($wallet, $account, $source, 500, 'USD', 'reservation-link-2');

        $this->expectException(\InvalidArgumentException::class);
        new FinancialOperationLink(TransactionType::Refund, $source, $result, 500, $reservation);
    }
}
