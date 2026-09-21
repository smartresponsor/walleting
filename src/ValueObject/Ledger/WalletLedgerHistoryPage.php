<?php

declare(strict_types=1);

namespace App\Walleting\ValueObject\Ledger;

final readonly class WalletLedgerHistoryPage
{
    /** @param list<WalletLedgerHistoryItem> $items */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
    ) {
    }
}
