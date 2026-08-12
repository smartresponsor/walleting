<?php

declare(strict_types=1);

namespace App\Walleting\Inbox;

final readonly class InboxHealthSnapshot
{
    /** @param array<string, int> $statusCounts */
    public function __construct(
        public array $statusCounts,
        public ?int $oldestProcessingAgeSeconds,
        public int $stuckProcessingCount,
    ) {
        if (null !== $oldestProcessingAgeSeconds && $oldestProcessingAgeSeconds < 0) {
            throw new \InvalidArgumentException('Inbox processing age cannot be negative.');
        }
        if ($stuckProcessingCount < 0) {
            throw new \InvalidArgumentException('Inbox stuck count cannot be negative.');
        }
    }

    public function isHealthy(): bool
    {
        return 0 === $this->stuckProcessingCount;
    }
}
