<?php

declare(strict_types=1);

namespace App\Walleting\Tests\Service;

use App\Walleting\Entity\Account;
use App\Walleting\Entity\Wallet;
use App\Walleting\Enum\AccountCategory;
use App\Walleting\Ledger\FeeAllocation;
use App\Walleting\Service\FeePostingComposer;
use PHPUnit\Framework\TestCase;

final class FeePostingComposerTest extends TestCase
{
    public function testGrossSettlementIsSplitIntoNetAndFeeLegs(): void
    {
        $wallet = new Wallet('vendor', 'fee-composer-wallet');
        $source = new Account($wallet, 'reserve', 'USD', AccountCategory::Reserve);
        $net = new Account($wallet, 'vendor-net', 'USD', AccountCategory::Liability);
        $platform = new Account($wallet, 'platform-fee', 'USD', AccountCategory::Revenue);
        $provider = new Account($wallet, 'provider-fee', 'USD', AccountCategory::Clearing);

        $plan = (new FeePostingComposer())->compose($source, $net, 1000, [
            new FeeAllocation('platform_fee', $platform, 100),
            new FeeAllocation('provider_fee', $provider, 50),
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
        $source = new Account($wallet, 'reserve', 'USD', AccountCategory::Reserve);
        $net = new Account($wallet, 'vendor-net', 'USD', AccountCategory::Liability);
        $platform = new Account($wallet, 'platform-fee', 'USD', AccountCategory::Revenue);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Total fees must be lower than the gross settlement amount.');
        (new FeePostingComposer())->compose($source, $net, 1000, [new FeeAllocation('platform_fee', $platform, 1000)]);
    }
}
