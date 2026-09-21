<?php

declare(strict_types=1);

namespace App\Walleting\ValueObject\Ledger;

use App\Walleting\Entity\WalletAccount;

final readonly class WalletFeeAllocation
{
    public function __construct(
        public string $code,
        public WalletAccount $account,
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
