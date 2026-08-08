<?php

declare(strict_types=1);

namespace App\Posting;

final readonly class PostingSloTrendAssessment
{
    /** @param list<string> $reasons */
    public function __construct(
        public PostingHealthStatus $status,
        public PostingHealthAssessment $shortAssessment,
        public PostingHealthAssessment $longAssessment,
        public float $shortRetryBurnRate,
        public float $longRetryBurnRate,
        public float $shortFailureBurnRate,
        public float $longFailureBurnRate,
        public array $reasons,
    ) {
    }
}
