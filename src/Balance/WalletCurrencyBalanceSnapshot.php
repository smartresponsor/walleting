<?php

declare(strict_types=1);

namespace App\Balance;

final readonly class WalletCurrencyBalanceSnapshot
{
    public function __construct(
        public string $currency,
        public int $availableMinor,
        public int $reservedMinor,
    ) {
        if (1 !== preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new \InvalidArgumentException('Wallet balance currency must be an ISO 4217 alpha-3 code.');
        }
    }

    public function totalMinor(): int
    {
        return $this->availableMinor + $this->reservedMinor;
    }
}
