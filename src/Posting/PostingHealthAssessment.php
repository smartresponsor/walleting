<?php

declare(strict_types=1);

namespace App\Walleting\Posting;

final readonly class PostingHealthAssessment
{
    /** @param list<string> $reasons */
    public function __construct(
        public PostingHealthStatus $status,
        public array $reasons,
    ) {
    }
}
