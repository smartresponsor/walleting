<?php

declare(strict_types=1);

namespace App\Walleting\Posting;

final readonly class PostingSloPolicy
{
    public function __construct(
        public int $minimumSamples = 20,
        public float $degradedRetryRate = 0.10,
        public float $criticalRetryRate = 0.25,
        public float $degradedFailureRate = 0.01,
        public float $criticalFailureRate = 0.05,
        public int $degradedP95Milliseconds = 1000,
        public int $criticalP95Milliseconds = 3000,
        public int $degradedContentionCount = 3,
        public int $criticalContentionCount = 10,
    ) {
        if ($minimumSamples < 1 || $minimumSamples > 100000) {
            throw new \InvalidArgumentException('Posting SLO minimum samples must be between 1 and 100000.');
        }
        if (!$this->validRatePair($degradedRetryRate, $criticalRetryRate) || !$this->validRatePair($degradedFailureRate, $criticalFailureRate)) {
            throw new \InvalidArgumentException('Posting SLO rate thresholds are invalid.');
        }
        if ($degradedP95Milliseconds < 1 || $criticalP95Milliseconds < $degradedP95Milliseconds || $criticalP95Milliseconds > 600000) {
            throw new \InvalidArgumentException('Posting SLO p95 thresholds are invalid.');
        }
        if ($degradedContentionCount < 0 || $criticalContentionCount < $degradedContentionCount) {
            throw new \InvalidArgumentException('Posting SLO contention thresholds are invalid.');
        }
    }

    public function assess(PostingHealthSnapshot $snapshot): PostingHealthAssessment
    {
        if ($snapshot->executionCount < $this->minimumSamples) {
            return new PostingHealthAssessment(PostingHealthStatus::Degraded, ['insufficient_samples']);
        }

        $contentionCount = $snapshot->lockTimeoutCount + $snapshot->deadlockCount;
        $criticalReasons = [];
        $degradedReasons = [];
        $this->classify($snapshot->retryRate, $this->degradedRetryRate, $this->criticalRetryRate, 'retry_rate', $degradedReasons, $criticalReasons);
        $this->classify($snapshot->failureRate, $this->degradedFailureRate, $this->criticalFailureRate, 'failure_rate', $degradedReasons, $criticalReasons);
        if (null !== $snapshot->p95LatencyMilliseconds) {
            $this->classify($snapshot->p95LatencyMilliseconds, $this->degradedP95Milliseconds, $this->criticalP95Milliseconds, 'p95_latency', $degradedReasons, $criticalReasons);
        }
        $this->classify($contentionCount, $this->degradedContentionCount, $this->criticalContentionCount, 'contention', $degradedReasons, $criticalReasons);

        if ([] !== $criticalReasons) {
            return new PostingHealthAssessment(PostingHealthStatus::Critical, array_values($criticalReasons));
        }
        if ([] !== $degradedReasons) {
            return new PostingHealthAssessment(PostingHealthStatus::Degraded, $degradedReasons);
        }

        return new PostingHealthAssessment(PostingHealthStatus::Healthy, []);
    }

    private function validRatePair(float $degraded, float $critical): bool
    {
        return $degraded >= 0.0 && $degraded <= 1.0 && $critical >= $degraded && $critical <= 1.0;
    }

    /** @param list<string> $degradedReasons @param list<string> $criticalReasons */
    private function classify(float|int $value, float|int $degradedThreshold, float|int $criticalThreshold, string $reason, array &$degradedReasons, array &$criticalReasons): void
    {
        if ($value > $criticalThreshold) {
            $criticalReasons[] = $reason;
        } elseif ($value > $degradedThreshold) {
            $degradedReasons[] = $reason;
        }
    }
}
