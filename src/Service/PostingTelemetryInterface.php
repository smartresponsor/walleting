<?php

declare(strict_types=1);

namespace App\Walleting\Service;

use App\Walleting\Posting\PostingExecutionMetric;

interface PostingTelemetryInterface
{
    public function record(PostingExecutionMetric $metric): void;
}
