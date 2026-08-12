<?php

declare(strict_types=1);

namespace App\Walleting\Service;

use App\Walleting\Posting\PostingExecutionMetric;
use Psr\Log\LoggerInterface;

final readonly class LoggingPostingTelemetry implements PostingTelemetryInterface
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function record(PostingExecutionMetric $metric): void
    {
        $level = match ($metric->event) {
            'failed' => 'warning',
            'retry' => 'notice',
            default => 'info',
        };

        $this->logger->log($level, 'walleting.posting.execution', $metric->context());
    }
}
