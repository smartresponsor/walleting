<?php

declare(strict_types=1);

namespace App\Posting;

final readonly class PostingHealthSnapshot
{
    public function __construct(
        public int $windowSeconds,
        public int $executionCount,
        public int $completedCount,
        public int $failedCount,
        public int $retriedExecutionCount,
        public int $retryEventCount,
        public float $retryRate,
        public float $failureRate,
        public ?int $p95LatencyMilliseconds,
        public int $lockTimeoutCount,
        public int $deadlockCount,
    ) {
        if ($windowSeconds < 1 || $executionCount < 0 || $completedCount < 0 || $failedCount < 0 || $retriedExecutionCount < 0 || $retryEventCount < 0 || $retryRate < 0 || $failureRate < 0 || $lockTimeoutCount < 0 || $deadlockCount < 0 || (null !== $p95LatencyMilliseconds && $p95LatencyMilliseconds < 0)) {
            throw new \InvalidArgumentException('Posting health snapshot values are invalid.');
        }
    }

    public function isHealthy(float $maximumRetryRate, float $maximumFailureRate, int $maximumP95LatencyMilliseconds): bool
    {
        return $this->retryRate <= $maximumRetryRate
            && $this->failureRate <= $maximumFailureRate
            && (null === $this->p95LatencyMilliseconds || $this->p95LatencyMilliseconds <= $maximumP95LatencyMilliseconds);
    }
}
