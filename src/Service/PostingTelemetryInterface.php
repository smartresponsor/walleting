<?php

declare(strict_types=1);

namespace App\Service;

use App\Posting\PostingExecutionMetric;

interface PostingTelemetryInterface
{
    public function record(PostingExecutionMetric $metric): void;
}
