<?php

declare(strict_types=1);

namespace App\Walleting\Tests\Service;

use App\Walleting\Entity\Funding;
use App\Walleting\Entity\PaymentInstrument;
use App\Walleting\Entity\Wallet;
use App\Walleting\Entity\Withdrawal;
use App\Walleting\Enum\PaymentInstrumentType;
use App\Walleting\Enum\FundingStatus;
use App\Walleting\Enum\WithdrawalStatus;
use App\Walleting\Service\BalanceReadService;
use App\Walleting\Service\LedgerQueryService;
use App\Walleting\Service\StatementQueryService;
use App\Walleting\Service\WalletingFacade;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class WalletingFacadeTest extends TestCase
{
    public function testFundingAndWithdrawalViewsExposeStableHostContract(): void
    {
        $connection = $this->createStub(Connection::class);
        $facade = new WalletingFacade(new BalanceReadService($connection), new LedgerQueryService($connection), new StatementQueryService($connection), $connection);
        $wallet = new Wallet('vendor', 'facade-unit-wallet');
        $card = new PaymentInstrument($wallet, PaymentInstrumentType::Card, 'stripe', 'pm_facade_unit', 'Card');
        $bank = new PaymentInstrument($wallet, PaymentInstrumentType::BankAccount, 'ach', 'bank_facade_unit', 'Bank');
        $funding = new Funding($wallet, $card, 1200, 'USD', 'facade-unit-funding');
        $withdrawal = new Withdrawal($wallet, $bank, 400, 'USD', 'facade-unit-withdrawal');

        $fundingView = $facade->funding($funding);
        self::assertSame('funding', $fundingView->type);
        self::assertSame(FundingStatus::Pending->value, $fundingView->status);
        self::assertSame(1200, $fundingView->amountMinor);
        self::assertSame('stripe', $fundingView->provider);
        self::assertSame('pm_facade_unit', $fundingView->providerReference);
        self::assertNull($fundingView->transactionId);

        $withdrawalView = $facade->withdrawal($withdrawal);
        self::assertSame('withdrawal', $withdrawalView->type);
        self::assertSame(WithdrawalStatus::Pending->value, $withdrawalView->status);
        self::assertSame(400, $withdrawalView->amountMinor);
        self::assertSame('ach', $withdrawalView->provider);
        self::assertSame('bank_facade_unit', $withdrawalView->providerReference);
    }
}
