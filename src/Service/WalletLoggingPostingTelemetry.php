<?php

declare(strict_types=1);

namespace App\Walleting\Service;

use App\Walleting\ValueObject\Posting\WalletPostingExecutionMetric;
use Psr\Log\LoggerInterface;

final readonly class WalletLoggingPostingTelemetry implements WalletPostingTelemetryInterface
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function record(WalletPostingExecutionMetric $metric): void
    {
        $level = match ($metric->event) {
            'failed' => 'warning',
            'retry' => 'notice',
            default => 'info',
        };

        $this->logger->log($level, 'walleting.posting.execution', $metric->context());
    }
}
