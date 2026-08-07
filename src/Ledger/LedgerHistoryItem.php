<?php

declare(strict_types=1);

namespace App\Ledger;

final readonly class LedgerHistoryItem
{
    public function __construct(
        public string $postingId,
        public string $transactionId,
        public string $transactionType,
        public string $idempotencyKey,
        public int $amountMinor,
        public string $currency,
        public int $sequence,
        public \DateTimeImmutable $postedAt,
    ) {
        if ('' === trim($postingId) || '' === trim($transactionId) || '' === trim($transactionType) || '' === trim($idempotencyKey)) {
            throw new \InvalidArgumentException('Ledger history identity fields are required.');
        }
    }
}
