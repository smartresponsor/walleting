<?php

declare(strict_types=1);

namespace App\Walleting\Tests\Service;

use App\Walleting\Entity\Wallet;
use App\Walleting\Entity\WalletAccount;
use App\Walleting\Enum\WalletAccountCategory;
use App\Walleting\Service\WalletFeePostingComposer;
use App\Walleting\ValueObject\Ledger\WalletFeeAllocation;
use PHPUnit\Framework\TestCase;

final class FeePostingComposerTest extends TestCase
{
    public function testGrossSettlementIsSplitIntoNetAndFeeLegs(): void
    {
        $wallet = new Wallet('vendor', 'fee-composer-wallet');
        $source = new WalletAccount($wallet, 'reserve', 'USD', WalletAccountCategory::Reserve);
        $net = new WalletAccount($wallet, 'vendor-net', 'USD', WalletAccountCategory::Liability);
        $platform = new WalletAccount($wallet, 'platform-fee', 'USD', WalletAccountCategory::Revenue);
        $provider = new WalletAccount($wallet, 'provider-fee', 'USD', WalletAccountCategory::Clearing);

        $plan = (new WalletFeePostingComposer())->compose($source, $net, 1000, [
            new WalletFeeAllocation('platform_fee', $platform, 100),
            new WalletFeeAllocation('provider_fee', $provider, 50),
        ]);

        self::assertSame(1000, $plan->grossAmountMinor);
        self::assertSame(850, $plan->netAmountMinor);
        self::assertSame(150, $plan->feeAmountMinor);
        self::assertSame([-1000, 850, 100, 50], array_map(static fn ($instruction): int => $instruction->amountMinor, $plan->instructions));
        self::assertSame(0, array_sum(array_map(static fn ($instruction): int => $instruction->amountMinor, $plan->instructions)));
        self::assertSame('platform_fee', $plan->metadata['fees'][0]['code']);
        self::assertSame(100, $plan->metadata['fees'][0]['amount_minor']);
    }

    public function testFeesCannotConsumeTheEntireGrossAmount(): void
    {
        $wallet = new Wallet('vendor', 'fee-composer-overflow-wallet');
        $source = new WalletAccount($wallet, 'reserve', 'USD', WalletAccountCategory::Reserve);
        $net = new WalletAccount($wallet, 'vendor-net', 'USD', WalletAccountCategory::Liability);
        $platform = new WalletAccount($wallet, 'platform-fee', 'USD', WalletAccountCategory::Revenue);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Total fees must be lower than the gross settlement amount.');
        (new WalletFeePostingComposer())->compose($source, $net, 1000, [new WalletFeeAllocation('platform_fee', $platform, 1000)]);
    }
}
