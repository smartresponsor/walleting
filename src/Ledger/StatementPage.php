<?php

declare(strict_types=1);

namespace App\Ledger;

final readonly class StatementPage
{
    /** @param list<StatementActivity> $items */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
    ) {
    }
}
