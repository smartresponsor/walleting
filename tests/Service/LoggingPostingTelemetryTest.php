<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Posting\PostingExecutionMetric;
use App\Service\LoggingPostingTelemetry;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class LoggingPostingTelemetryTest extends TestCase
{
    public function testRetryMetricIsLoggedWithStructuredContext(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $metric = new PostingExecutionMetric('retry', 'transfer', 1, 1, 'lock_timeout', 100, 100, 100);

        $logger->expects(self::once())
            ->method('log')
            ->with('notice', 'walleting.posting.execution', $metric->context());

        (new LoggingPostingTelemetry($logger))->record($metric);
    }
}
