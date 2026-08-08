<?php

declare(strict_types=1);

namespace App\Tests\Posting;

use App\Posting\PostingHealthSnapshot;
use App\Posting\PostingHealthStatus;
use App\Posting\PostingSloPolicy;
use PHPUnit\Framework\TestCase;

final class PostingSloPolicyTest extends TestCase
{
    public function testInsufficientSamplesAreDegradedInsteadOfCritical(): void
    {
        $assessment = (new PostingSloPolicy(minimumSamples: 20))->assess(
            new PostingHealthSnapshot(3600, 1, 0, 1, 1, 2, 1.0, 1.0, 9000, 5, 5),
        );

        self::assertSame(PostingHealthStatus::Degraded, $assessment->status);
        self::assertSame(['insufficient_samples'], $assessment->reasons);
    }

    public function testHealthySnapshotStaysHealthyWhenAllSignalsAreWithinThresholds(): void
    {
        $assessment = (new PostingSloPolicy())->assess(
            new PostingHealthSnapshot(3600, 100, 100, 0, 2, 2, 0.02, 0.0, 200, 1, 0),
        );

        self::assertSame(PostingHealthStatus::Healthy, $assessment->status);
        self::assertSame([], $assessment->reasons);
    }

    public function testDegradedStatusCollectsNonCriticalBreaches(): void
    {
        $assessment = (new PostingSloPolicy())->assess(
            new PostingHealthSnapshot(3600, 100, 98, 2, 15, 15, 0.15, 0.02, 1500, 2, 2),
        );

        self::assertSame(PostingHealthStatus::Degraded, $assessment->status);
        self::assertSame(['retry_rate', 'failure_rate', 'p95_latency', 'contention'], $assessment->reasons);
    }

    public function testCriticalStatusWinsWhenAnyCriticalThresholdIsExceeded(): void
    {
        $assessment = (new PostingSloPolicy())->assess(
            new PostingHealthSnapshot(3600, 100, 90, 10, 30, 30, 0.30, 0.10, 5000, 8, 5),
        );

        self::assertSame(PostingHealthStatus::Critical, $assessment->status);
        self::assertSame(['retry_rate', 'failure_rate', 'p95_latency', 'contention'], $assessment->reasons);
    }
}
