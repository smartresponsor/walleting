<?php

declare(strict_types=1);

namespace App\Ledger;

final readonly class FeePostingPlan
{
    /** @param non-empty-list<PostingInstruction> $instructions @param array<string, mixed> $metadata */
    public function __construct(
        public array $instructions,
        public array $metadata,
        public int $grossAmountMinor,
        public int $netAmountMinor,
        public int $feeAmountMinor,
    ) {
        if ($grossAmountMinor <= 0 || $netAmountMinor <= 0 || $feeAmountMinor < 0 || $netAmountMinor + $feeAmountMinor !== $grossAmountMinor) {
            throw new \InvalidArgumentException('Fee posting plan totals are invalid.');
        }
    }
}
