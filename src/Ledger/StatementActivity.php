<?php

declare(strict_types=1);

namespace App\Walleting\Ledger;

final readonly class StatementActivity
{
    /** @param array<string, mixed> $metadata @param list<array{account_id:string,code:string,category:string,amount_minor:int}> $counterparties */
    public function __construct(
        public string $transactionId,
        public string $transactionType,
        public string $idempotencyKey,
        public int $amountMinor,
        public int $runningBalanceMinor,
        public string $currency,
        public \DateTimeImmutable $postedAt,
        public array $metadata,
        public array $counterparties,
    ) {
        if ('' === trim($transactionId) || '' === trim($transactionType) || '' === trim($idempotencyKey) || 1 !== preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new \InvalidArgumentException('Statement activity identity and currency are required.');
        }
    }

    public function operation(): ?string
    {
        $operation = $this->metadata['operation'] ?? null;

        return is_string($operation) && '' !== trim($operation) ? $operation : null;
    }
}
