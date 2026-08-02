<?php

declare(strict_types=1);

namespace App\ReadModel;

final readonly class BalanceSnapshot
{
    public function __construct(
        public string $currency,
        public int $ledgerMinor,
        public int $availableMinor,
        public int $reservedMinor,
        public int $postingCount,
        public \DateTimeImmutable $asOf,
    ) {
        if (1 !== preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new \InvalidArgumentException('Balance snapshot currency must be an ISO 4217 alpha-3 code.');
        }
        if ($postingCount < 0) {
            throw new \InvalidArgumentException('Balance snapshot posting count cannot be negative.');
        }
        if ($ledgerMinor !== $availableMinor + $reservedMinor) {
            throw new \InvalidArgumentException('Ledger balance must equal available plus reserved balance.');
        }
    }

    public function isEmpty(): bool
    {
        return 0 === $this->ledgerMinor && 0 === $this->postingCount;
    }
}
