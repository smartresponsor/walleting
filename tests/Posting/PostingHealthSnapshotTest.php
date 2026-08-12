<?php

declare(strict_types=1);

namespace App\Walleting\Tests\Posting;

use App\Walleting\Posting\PostingHealthSnapshot;
use PHPUnit\Framework\TestCase;

final class PostingHealthSnapshotTest extends TestCase
{
    public function testThresholdEvaluationUsesRetryFailureAndP95Limits(): void
    {
        $snapshot = new PostingHealthSnapshot(3600, 100, 97, 3, 8, 10, 0.08, 0.03, 420, 4, 1);

        self::assertTrue($snapshot->isHealthy(0.10, 0.05, 500));
        self::assertFalse($snapshot->isHealthy(0.05, 0.05, 500));
        self::assertFalse($snapshot->isHealthy(0.10, 0.02, 500));
        self::assertFalse($snapshot->isHealthy(0.10, 0.05, 400));
    }

    public function testNoLatencySamplesCanStillBeHealthy(): void
    {
        $snapshot = new PostingHealthSnapshot(3600, 0, 0, 0, 0, 0, 0.0, 0.0, null, 0, 0);

        self::assertTrue($snapshot->isHealthy(0.0, 0.0, 1));
    }
}
