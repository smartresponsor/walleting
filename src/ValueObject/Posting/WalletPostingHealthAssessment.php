<?php

declare(strict_types=1);

namespace App\Walleting\ValueObject\Posting;

final readonly class WalletPostingHealthAssessment
{
    /** @param list<string> $reasons */
    public function __construct(
        public WalletPostingHealthStatus $status,
        public array $reasons,
    ) {
    }
}
