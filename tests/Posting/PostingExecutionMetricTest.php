<?php

declare(strict_types=1);

namespace App\Tests\Posting;

use App\Posting\PostingExecutionMetric;
use PHPUnit\Framework\TestCase;

final class PostingExecutionMetricTest extends TestCase
{
    public function testContextContainsOnlyOperationalFields(): void
    {
        $metric = new PostingExecutionMetric('retry', 'transfer', 2, 2, 'lock_timeout', 101, 142, 101);

        self::assertSame([
            'event' => 'retry',
            'transaction_type' => 'transfer',
            'attempt' => 2,
            'retry_count' => 2,
            'retry_reason' => 'lock_timeout',
            'attempt_duration_ms' => 101,
            'total_duration_ms' => 142,
            'lock_wait_ms' => 101,
        ], $metric->context());
        self::assertArrayNotHasKey('amount_minor', $metric->context());
        self::assertArrayNotHasKey('account_id', $metric->context());
        self::assertArrayNotHasKey('metadata', $metric->context());
        self::assertArrayNotHasKey('idempotency_key', $metric->context());
    }
}
