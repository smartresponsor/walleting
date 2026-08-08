<?php

declare(strict_types=1);

namespace App\Posting;

final readonly class PostingSloTrendPolicy
{
    public function __construct(
        public PostingSloPolicy $shortPolicy,
        public PostingSloPolicy $longPolicy,
        public float $criticalShortBurnRate = 5.0,
        public float $criticalLongBurnRate = 2.0,
    ) {
        if ($criticalShortBurnRate < 1.0 || $criticalShortBurnRate > 1000.0 || $criticalLongBurnRate < 1.0 || $criticalLongBurnRate > $criticalShortBurnRate) {
            throw new \InvalidArgumentException('Posting SLO burn-rate thresholds are invalid.');
        }
    }

    public function assess(PostingHealthSnapshot $short, PostingHealthSnapshot $long): PostingSloTrendAssessment
    {
        if ($short->windowSeconds >= $long->windowSeconds) {
            throw new \InvalidArgumentException('Posting SLO short window must be smaller than long window.');
        }

        $shortAssessment = $this->shortPolicy->assess($short);
        $longAssessment = $this->longPolicy->assess($long);
        $shortRetryBurn = $this->burnRate($short->retryRate, $this->shortPolicy->degradedRetryRate);
        $longRetryBurn = $this->burnRate($long->retryRate, $this->longPolicy->degradedRetryRate);
        $shortFailureBurn = $this->burnRate($short->failureRate, $this->shortPolicy->degradedFailureRate);
        $longFailureBurn = $this->burnRate($long->failureRate, $this->longPolicy->degradedFailureRate);

        if ($long->executionCount < $this->longPolicy->minimumSamples) {
            return new PostingSloTrendAssessment(
                PostingHealthStatus::Degraded,
                $shortAssessment,
                $longAssessment,
                $shortRetryBurn,
                $longRetryBurn,
                $shortFailureBurn,
                $longFailureBurn,
                ['insufficient_long_samples'],
            );
        }

        if ($short->executionCount < $this->shortPolicy->minimumSamples) {
            return new PostingSloTrendAssessment(
                PostingHealthStatus::Degraded,
                $shortAssessment,
                $longAssessment,
                $shortRetryBurn,
                $longRetryBurn,
                $shortFailureBurn,
                $longFailureBurn,
                ['insufficient_short_samples'],
            );
        }

        $reasons = [];
        $criticalBurn = ($shortRetryBurn >= $this->criticalShortBurnRate && $longRetryBurn >= $this->criticalLongBurnRate)
            || ($shortFailureBurn >= $this->criticalShortBurnRate && $longFailureBurn >= $this->criticalLongBurnRate);
        if ($criticalBurn) {
            $reasons[] = 'sustained_burn_rate';
        }

        if (PostingHealthStatus::Critical === $shortAssessment->status && PostingHealthStatus::Critical === $longAssessment->status) {
            $reasons[] = 'sustained_critical';
        }

        if ([] !== $reasons) {
            return new PostingSloTrendAssessment(
                PostingHealthStatus::Critical,
                $shortAssessment,
                $longAssessment,
                $shortRetryBurn,
                $longRetryBurn,
                $shortFailureBurn,
                $longFailureBurn,
                $reasons,
            );
        }

        if (PostingHealthStatus::Healthy !== $shortAssessment->status && PostingHealthStatus::Healthy === $longAssessment->status) {
            return new PostingSloTrendAssessment(
                PostingHealthStatus::Degraded,
                $shortAssessment,
                $longAssessment,
                $shortRetryBurn,
                $longRetryBurn,
                $shortFailureBurn,
                $longFailureBurn,
                ['short_window_spike'],
            );
        }

        if (PostingHealthStatus::Healthy !== $shortAssessment->status || PostingHealthStatus::Healthy !== $longAssessment->status) {
            return new PostingSloTrendAssessment(
                PostingHealthStatus::Degraded,
                $shortAssessment,
                $longAssessment,
                $shortRetryBurn,
                $longRetryBurn,
                $shortFailureBurn,
                $longFailureBurn,
                ['sustained_degradation'],
            );
        }

        return new PostingSloTrendAssessment(
            PostingHealthStatus::Healthy,
            $shortAssessment,
            $longAssessment,
            $shortRetryBurn,
            $longRetryBurn,
            $shortFailureBurn,
            $longFailureBurn,
            [],
        );
    }

    private function burnRate(float $observedRate, float $budgetRate): float
    {
        if (0.0 === $budgetRate) {
            return 0.0 === $observedRate ? 0.0 : INF;
        }

        return $observedRate / $budgetRate;
    }
}
