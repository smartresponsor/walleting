<?php

declare(strict_types=1);

namespace App\Walleting\Policy\Posting;

use App\Walleting\ValueObject\Posting\WalletPostingHealthAssessment;
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
        $this->assertWindowOrder($short, $long);

        $shortAssessment = $this->shortPolicy->assess($short);
        $longAssessment = $this->longPolicy->assess($long);
        $burnRates = $this->burnRates($short, $long);
        [$status, $reasons] = $this->statusAndReasons($short, $long, $shortAssessment, $longAssessment, $burnRates);

        return new WalletPostingSloTrendAssessment(
            $status,
            $shortAssessment,
            $longAssessment,
            $burnRates['short_retry'],
            $burnRates['long_retry'],
            $burnRates['short_failure'],
            $burnRates['long_failure'],
            $reasons,
        );
    }

    private function assertWindowOrder(WalletPostingHealthSnapshot $short, WalletPostingHealthSnapshot $long): void
    {
        if ($short->windowSeconds >= $long->windowSeconds) {
            throw new \InvalidArgumentException('Posting SLO short window must be smaller than long window.');
        }
    }

    /** @return array{short_retry: float, long_retry: float, short_failure: float, long_failure: float} */
    private function burnRates(WalletPostingHealthSnapshot $short, WalletPostingHealthSnapshot $long): array
    {
        return [
            'short_retry' => $this->burnRate($short->retryRate, $this->shortPolicy->degradedRetryRate),
            'long_retry' => $this->burnRate($long->retryRate, $this->longPolicy->degradedRetryRate),
            'short_failure' => $this->burnRate($short->failureRate, $this->shortPolicy->degradedFailureRate),
            'long_failure' => $this->burnRate($long->failureRate, $this->longPolicy->degradedFailureRate),
        ];
    }

    /**
     * @param array{short_retry: float, long_retry: float, short_failure: float, long_failure: float} $burnRates
     *
     * @return array{WalletPostingHealthStatus, list<string>}
     */
    private function statusAndReasons(
        WalletPostingHealthSnapshot $short,
        WalletPostingHealthSnapshot $long,
        WalletPostingHealthAssessment $shortAssessment,
        WalletPostingHealthAssessment $longAssessment,
        array $burnRates,
    ): array {
        if ($long->executionCount < $this->longPolicy->minimumSamples) {
            return [WalletPostingHealthStatus::Degraded, ['insufficient_long_samples']];
        }
        if ($short->executionCount < $this->shortPolicy->minimumSamples) {
            return [WalletPostingHealthStatus::Degraded, ['insufficient_short_samples']];
        }

        $criticalReasons = $this->criticalReasons($shortAssessment, $longAssessment, $burnRates);
        if ([] !== $criticalReasons) {
            return [WalletPostingHealthStatus::Critical, $criticalReasons];
        }
        if (WalletPostingHealthStatus::Healthy !== $shortAssessment->status && WalletPostingHealthStatus::Healthy === $longAssessment->status) {
            return [WalletPostingHealthStatus::Degraded, ['short_window_spike']];
        }
        if (WalletPostingHealthStatus::Healthy !== $shortAssessment->status || WalletPostingHealthStatus::Healthy !== $longAssessment->status) {
            return [WalletPostingHealthStatus::Degraded, ['sustained_degradation']];
        }

        return [WalletPostingHealthStatus::Healthy, []];
    }

    /**
     * @param array{short_retry: float, long_retry: float, short_failure: float, long_failure: float} $burnRates
     *
     * @return list<string>
     */
    private function criticalReasons(WalletPostingHealthAssessment $shortAssessment, WalletPostingHealthAssessment $longAssessment, array $burnRates): array
    {
        $reasons = [];
        if (($burnRates['short_retry'] >= $this->criticalShortBurnRate && $burnRates['long_retry'] >= $this->criticalLongBurnRate)
            || ($burnRates['short_failure'] >= $this->criticalShortBurnRate && $burnRates['long_failure'] >= $this->criticalLongBurnRate)) {
            $reasons[] = 'sustained_burn_rate';
        }
        if (WalletPostingHealthStatus::Critical === $shortAssessment->status && WalletPostingHealthStatus::Critical === $longAssessment->status) {
            $reasons[] = 'sustained_critical';
        }

        return $reasons;
    }

    private function burnRate(float $observedRate, float $budgetRate): float
    {
        if (0.0 === $budgetRate) {
            return 0.0 === $observedRate ? 0.0 : INF;
        }

        return $observedRate / $budgetRate;
    }
}
