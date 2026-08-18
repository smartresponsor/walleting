<?php

declare(strict_types=1);

namespace App\Walleting\Ledger;

final readonly class WalletTransactionItem
{
    public function __construct(
        public string $transactionId,
        public string $type,
        public string $idempotencyKey,
        public int $amountMinor,
        public string $currency,
        public \DateTimeImmutable $postedAt,
    ) {
        if ('' === trim($transactionId) || '' === trim($type) || '' === trim($idempotencyKey)) {
            throw new \InvalidArgumentException('Wallet transaction identity fields are required.');
        }
    }
}
