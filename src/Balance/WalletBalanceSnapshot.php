<?php

declare(strict_types=1);

namespace App\Balance;

final readonly class WalletBalanceSnapshot
{
    /** @param list<WalletCurrencyBalanceSnapshot> $currencies */
    public function __construct(
        public string $walletId,
        public array $currencies,
    ) {
        if ('' === trim($walletId)) {
            throw new \InvalidArgumentException('Wallet balance snapshot wallet id is required.');
        }
    }
}
