<?php

declare(strict_types=1);

namespace App\Walleting\Tests\Service;

use App\Walleting\Entity\Wallet;
use App\Walleting\Entity\WalletFunding;
use App\Walleting\Entity\WalletPaymentInstrument;
use App\Walleting\Entity\WalletWithdrawal;
use App\Walleting\Enum\WalletFundingStatus;
use App\Walleting\Enum\WalletPaymentInstrumentType;
use App\Walleting\Enum\WalletWithdrawalStatus;
use App\Walleting\Service\WalletBalanceReadService;
use App\Walleting\Service\WalletingFacade;
use App\Walleting\Service\WalletLedgerQueryService;
use App\Walleting\Service\WalletStatementQueryService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class WalletingFacadeTest extends TestCase
{
    public function testFundingAndWithdrawalViewsExposeStableHostContract(): void
    {
        $connection = $this->createStub(Connection::class);
        $facade = new WalletingFacade(new WalletBalanceReadService($connection), new WalletLedgerQueryService($connection), new WalletStatementQueryService($connection), $connection);
        $wallet = new Wallet('vendor', 'facade-unit-wallet');
        $card = new WalletPaymentInstrument($wallet, WalletPaymentInstrumentType::Card, 'stripe', 'pm_facade_unit', 'Card');
        $bank = new WalletPaymentInstrument($wallet, WalletPaymentInstrumentType::BankAccount, 'ach', 'bank_facade_unit', 'Bank');
        $funding = new WalletFunding($wallet, $card, 1200, 'USD', 'facade-unit-funding');
        $funding->start();
        $funding->bindProviderOperationReference('ch_facade_unit');
        $withdrawal = new WalletWithdrawal($wallet, $bank, 400, 'USD', 'facade-unit-withdrawal');
        $withdrawal->start();
        $withdrawal->bindProviderOperationReference('po_facade_unit');

        $fundingView = $facade->funding($funding);
        self::assertSame('funding', $fundingView->type);
        self::assertSame(WalletFundingStatus::Processing->value, $fundingView->status);
        self::assertSame(1200, $fundingView->amountMinor);
        self::assertSame('stripe', $fundingView->provider);
        self::assertSame('pm_facade_unit', $fundingView->providerReference);
        self::assertSame('ch_facade_unit', $fundingView->providerOperationReference);
        self::assertNull($fundingView->transactionId);

        $withdrawalView = $facade->withdrawal($withdrawal);
        self::assertSame('withdrawal', $withdrawalView->type);
        self::assertSame(WalletWithdrawalStatus::Processing->value, $withdrawalView->status);
        self::assertSame(400, $withdrawalView->amountMinor);
        self::assertSame('ach', $withdrawalView->provider);
        self::assertSame('bank_facade_unit', $withdrawalView->providerReference);
        self::assertSame('po_facade_unit', $withdrawalView->providerOperationReference);
    }
}
