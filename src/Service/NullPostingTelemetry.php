<?php

declare(strict_types=1);

namespace App\Walleting\Service;

use App\Walleting\Posting\PostingExecutionMetric;

final readonly class NullPostingTelemetry implements PostingTelemetryInterface
{
    public function record(PostingExecutionMetric $metric): void
    {
    }
}
