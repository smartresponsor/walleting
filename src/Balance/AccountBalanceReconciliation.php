<?php

declare(strict_types=1);

namespace App\Balance;

final readonly class AccountBalanceReconciliation
{
    public function __construct(
        public string $accountId,
        public string $currency,
        public int $projectedBalanceMinor,
        public int $ledgerBalanceMinor,
        public int $projectedPostingCount,
        public int $ledgerPostingCount,
    ) {
        if ('' === trim($accountId) || 1 !== preg_match('/^[A-Z]{3}$/', $currency) || min($projectedPostingCount, $ledgerPostingCount) < 0) {
            throw new \InvalidArgumentException('Account reconciliation values are invalid.');
        }
    }

    public function isConsistent(): bool
    {
        return $this->projectedBalanceMinor === $this->ledgerBalanceMinor
            && $this->projectedPostingCount === $this->ledgerPostingCount;
    }
}
