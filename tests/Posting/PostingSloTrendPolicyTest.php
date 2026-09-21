<?php

declare(strict_types=1);

namespace App\Walleting\Tests\Posting;

use App\Walleting\Policy\Posting\WalletPostingSloPolicy;
use App\Walleting\Policy\Posting\WalletPostingSloTrendPolicy;
use App\Walleting\ValueObject\Posting\WalletPostingHealthSnapshot;
use App\Walleting\ValueObject\Posting\WalletPostingHealthStatus;
use PHPUnit\Framework\TestCase;

final class PostingSloTrendPolicyTest extends TestCase
{
    public function testShortSpikeIsDegradedWhenLongWindowIsHealthy(): void
    {
        $policy = $this->policy();
        $assessment = $policy->assess(
            $this->snapshot(900, 20, 0.20, 0.02, 1500, 2, 0),
            $this->snapshot(14400, 100, 0.02, 0.0, 200, 0, 0),
        );

        self::assertSame(WalletPostingHealthStatus::Degraded, $assessment->status);
        self::assertSame(['short_window_spike'], $assessment->reasons);
    }

    public function testSustainedModerateDegradationIsDegraded(): void
    {
        $assessment = $this->policy()->assess(
            $this->snapshot(900, 20, 0.15, 0.02, 1200, 2, 0),
            $this->snapshot(14400, 100, 0.12, 0.02, 1100, 2, 0),
        );

        self::assertSame(WalletPostingHealthStatus::Degraded, $assessment->status);
        self::assertSame(['sustained_degradation'], $assessment->reasons);
    }

    public function testSustainedFailureBurnCanBecomeCriticalBeforeSingleWindowCriticalThreshold(): void
    {
        $assessment = $this->policy()->assess(
            $this->snapshot(900, 20, 0.02, 0.05, 300, 0, 0),
            $this->snapshot(14400, 100, 0.02, 0.02, 300, 0, 0),
        );

        self::assertSame(WalletPostingHealthStatus::Critical, $assessment->status);
        self::assertContains('sustained_burn_rate', $assessment->reasons);
        self::assertSame(5.0, $assessment->shortFailureBurnRate);
        self::assertSame(2.0, $assessment->longFailureBurnRate);
    }

    public function testInsufficientLongWindowSamplesPreventCriticalAlert(): void
    {
        $assessment = $this->policy()->assess(
            $this->snapshot(900, 20, 0.50, 0.50, 5000, 10, 5),
            $this->snapshot(14400, 10, 0.50, 0.50, 5000, 10, 5),
        );

        self::assertSame(WalletPostingHealthStatus::Degraded, $assessment->status);
        self::assertSame(['insufficient_long_samples'], $assessment->reasons);
    }

    public function testInsufficientShortWindowSamplesAreNotReportedAsSpike(): void
    {
        $assessment = $this->policy()->assess(
            $this->snapshot(900, 5, 0.50, 0.50, 5000, 10, 5),
            $this->snapshot(14400, 100, 0.02, 0.0, 200, 0, 0),
        );

        self::assertSame(WalletPostingHealthStatus::Degraded, $assessment->status);
        self::assertSame(['insufficient_short_samples'], $assessment->reasons);
    }

    public function testHealthyWindowsProduceHealthyTrend(): void
    {
        $assessment = $this->policy()->assess(
            $this->snapshot(900, 20, 0.02, 0.0, 200, 0, 0),
            $this->snapshot(14400, 100, 0.02, 0.0, 200, 0, 0),
        );

        self::assertSame(WalletPostingHealthStatus::Healthy, $assessment->status);
        self::assertSame([], $assessment->reasons);
    }

    private function policy(): WalletPostingSloTrendPolicy
    {
        return new WalletPostingSloTrendPolicy(
            new WalletPostingSloPolicy(minimumSamples: 10),
            new WalletPostingSloPolicy(minimumSamples: 50),
            5.0,
            2.0,
        );
    }

    private function snapshot(int $window, int $executions, float $retryRate, float $failureRate, int $p95, int $lockTimeouts, int $deadlocks): WalletPostingHealthSnapshot
    {
        $failed = (int) round($executions * $failureRate);
        $retried = (int) round($executions * $retryRate);

        return new WalletPostingHealthSnapshot(
            $window,
            $executions,
            $executions - $failed,
            $failed,
            $retried,
            $retried,
            $retryRate,
            $failureRate,
            $p95,
            $lockTimeouts,
            $deadlocks,
        );
    }
}
