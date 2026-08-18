<?php

declare(strict_types=1);

namespace App\Walleting\Ledger;

final readonly class WalletTransactionPage
{
    /** @param list<WalletTransactionItem> $items */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
    ) {
    }
}
