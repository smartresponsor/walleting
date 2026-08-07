<?php

declare(strict_types=1);

namespace App\Service;

use App\Posting\PostingExecutionMetric;

final readonly class NullPostingTelemetry implements PostingTelemetryInterface
{
    public function record(PostingExecutionMetric $metric): void
    {
    }
}
