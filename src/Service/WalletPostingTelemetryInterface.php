<?php

declare(strict_types=1);

namespace App\Walleting\Service;

use App\Walleting\ValueObject\Posting\WalletPostingExecutionMetric;

interface WalletPostingTelemetryInterface
{
    public function record(WalletPostingExecutionMetric $metric): void;
}
