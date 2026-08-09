<?php

declare(strict_types=1);

namespace App\Ledger;

use App\Entity\Account;

final readonly class FeeAllocation
{
    public function __construct(
        public string $code,
        public Account $account,
        public int $amountMinor,
    ) {
        if ('' === trim($code)) {
            throw new \InvalidArgumentException('Fee code is required.');
        }
        if ($amountMinor <= 0) {
            throw new \InvalidArgumentException('Fee amount must be positive.');
        }
    }
}
