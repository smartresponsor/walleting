<?php

declare(strict_types=1);

namespace App\Ledger;

use App\Entity\Account;

final readonly class PostingEntry
{
    public function __construct(public Account $account, public int $amountMinor)
    {
        if (0 === $amountMinor) {
            throw new \InvalidArgumentException('Posting entry amount must be non-zero.');
        }
    }
}
