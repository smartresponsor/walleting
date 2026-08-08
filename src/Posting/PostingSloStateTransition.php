<?php

declare(strict_types=1);

namespace App\Posting;

final readonly class PostingSloStateTransition
{
    /** @param list<string> $reasons */
    public function __construct(
        public string $scope,
        public PostingHealthStatus $previousStatus,
        public PostingHealthStatus $currentStatus,
        public PostingHealthStatus $observedStatus,
        public ?PostingHealthStatus $pendingStatus,
        public int $pendingCount,
        public int $requiredCount,
        public bool $changed,
        public array $reasons,
    ) {
    }
}
