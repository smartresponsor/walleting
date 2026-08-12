<?php

declare(strict_types=1);

namespace App\Walleting\Posting;

final readonly class PostingExecutionMetric
{
    public function __construct(
        public string $event,
        public string $transactionType,
        public int $attempt,
        public int $retryCount,
        public ?string $retryReason,
        public int $attemptDurationMilliseconds,
        public int $totalDurationMilliseconds,
        public ?int $lockWaitMilliseconds,
    ) {
        if (!in_array($event, ['retry', 'completed', 'failed'], true)) {
            throw new \InvalidArgumentException('Posting metric event is invalid.');
        }
        if ('' === trim($transactionType) || $attempt < 1 || $retryCount < 0 || $attemptDurationMilliseconds < 0 || $totalDurationMilliseconds < 0) {
            throw new \InvalidArgumentException('Posting metric values are invalid.');
        }
        if (null !== $lockWaitMilliseconds && $lockWaitMilliseconds < 0) {
            throw new \InvalidArgumentException('Posting metric lock wait cannot be negative.');
        }
    }

    /** @return array<string, int|string|null> */
    public function context(): array
    {
        return [
            'event' => $this->event,
            'transaction_type' => $this->transactionType,
            'attempt' => $this->attempt,
            'retry_count' => $this->retryCount,
            'retry_reason' => $this->retryReason,
            'attempt_duration_ms' => $this->attemptDurationMilliseconds,
            'total_duration_ms' => $this->totalDurationMilliseconds,
            'lock_wait_ms' => $this->lockWaitMilliseconds,
        ];
    }
}
