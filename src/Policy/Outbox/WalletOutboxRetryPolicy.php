<?php

declare(strict_types=1);

namespace App\Walleting\Policy\Outbox;

final readonly class WalletOutboxRetryPolicy
{
    public function __construct(
        private int $maxAttempts = 8,
        private int $baseDelaySeconds = 30,
        private int $maxDelaySeconds = 3600,
    ) {
        if ($maxAttempts < 1 || $baseDelaySeconds < 1 || $maxDelaySeconds < $baseDelaySeconds) {
            throw new \InvalidArgumentException('Outbox retry policy is invalid.');
        }
    }

    public function isExhausted(int $attemptCount): bool
    {
        return $attemptCount >= $this->maxAttempts;
    }

    public function delaySeconds(int $attemptCount): int
    {
        $exponent = max(0, $attemptCount - 1);

        return min($this->maxDelaySeconds, $this->baseDelaySeconds * (2 ** $exponent));
    }
}
