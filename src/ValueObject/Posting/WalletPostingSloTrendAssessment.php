<?php

declare(strict_types=1);

namespace App\Walleting\ValueObject\Posting;

final readonly class WalletPostingSloTrendAssessment
{
    /** @param list<string> $reasons */
    public function __construct(
        public WalletPostingHealthStatus $status,
        public WalletPostingHealthAssessment $shortAssessment,
        public WalletPostingHealthAssessment $longAssessment,
        public float $shortRetryBurnRate,
        public float $longRetryBurnRate,
        public float $shortFailureBurnRate,
        public float $longFailureBurnRate,
        public array $reasons,
    ) {
    }
}
