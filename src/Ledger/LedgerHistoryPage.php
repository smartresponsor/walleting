<?php

declare(strict_types=1);

namespace App\Ledger;

final readonly class LedgerHistoryPage
{
    /** @param list<LedgerHistoryItem> $items */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
    ) {
    }
}
