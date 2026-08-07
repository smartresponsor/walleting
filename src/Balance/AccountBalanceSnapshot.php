<?php

declare(strict_types=1);

namespace App\Balance;

final readonly class AccountBalanceSnapshot
{
    public function __construct(
        public string $accountId,
        public string $currency,
        public int $balanceMinor,
        public int $postingCount,
        public ?\DateTimeImmutable $updatedAt,
    ) {
        if ('' === trim($accountId) || 1 !== preg_match('/^[A-Z]{3}$/', $currency) || $postingCount < 0) {
            throw new \InvalidArgumentException('Account balance snapshot identity, currency, and posting count are invalid.');
        }
    }
}
