<?php

declare(strict_types=1);

namespace App\Walleting\Policy\Posting;

use App\Walleting\ValueObject\Posting\WalletPostingHealthSnapshot;
use App\Walleting\ValueObject\Posting\WalletPostingHealthStatus;
use App\Walleting\ValueObject\Posting\WalletPostingSloTrendAssessment;

final readonly class WalletPostingSloTrendPolicy
{
    public function __construct(
        public WalletPostingSloPolicy $shortPolicy,
        public WalletPostingSloPolicy $longPolicy,
        public float $criticalShortBurnRate = 5.0,
        public float $criticalLongBurnRate = 2.0,
    ) {
        if ($criticalShortBurnRate < 1.0 || $criticalShortBurnRate > 1000.0 || $criticalLongBurnRate < 1.0 || $criticalLongBurnRate > $criticalShortBurnRate) {
            throw new \InvalidArgumentException('Posting SLO burn-rate thresholds are invalid.');
        }
    }

    public function assess(WalletPostingHealthSnapshot $short, WalletPostingHealthSnapshot $long): WalletPostingSloTrendAssessment
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
            return new WalletPostingSloTrendAssessment(
                WalletPostingHealthStatus::Degraded,
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
            return new WalletPostingSloTrendAssessment(
                WalletPostingHealthStatus::Degraded,
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

        if (WalletPostingHealthStatus::Critical === $shortAssessment->status && WalletPostingHealthStatus::Critical === $longAssessment->status) {
            $reasons[] = 'sustained_critical';
        }

        if ([] !== $reasons) {
            return new WalletPostingSloTrendAssessment(
                WalletPostingHealthStatus::Critical,
                $shortAssessment,
                $longAssessment,
                $shortRetryBurn,
                $longRetryBurn,
                $shortFailureBurn,
                $longFailureBurn,
                $reasons,
            );
        }

        if (WalletPostingHealthStatus::Healthy !== $shortAssessment->status && WalletPostingHealthStatus::Healthy === $longAssessment->status) {
            return new WalletPostingSloTrendAssessment(
                WalletPostingHealthStatus::Degraded,
                $shortAssessment,
                $longAssessment,
                $shortRetryBurn,
                $longRetryBurn,
                $shortFailureBurn,
                $longFailureBurn,
                ['short_window_spike'],
            );
        }

        if (WalletPostingHealthStatus::Healthy !== $shortAssessment->status || WalletPostingHealthStatus::Healthy !== $longAssessment->status) {
            return new WalletPostingSloTrendAssessment(
                WalletPostingHealthStatus::Degraded,
                $shortAssessment,
                $longAssessment,
                $shortRetryBurn,
                $longRetryBurn,
                $shortFailureBurn,
                $longFailureBurn,
                ['sustained_degradation'],
            );
        }

        return new WalletPostingSloTrendAssessment(
            WalletPostingHealthStatus::Healthy,
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
