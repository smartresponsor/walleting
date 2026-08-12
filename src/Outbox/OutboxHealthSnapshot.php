<?php

declare(strict_types=1);

namespace App\Walleting\Outbox;

final readonly class OutboxHealthSnapshot
{
    /** @param array<string, int> $statusCounts */
    public function __construct(
        public array $statusCounts,
        public ?int $oldestDispatchableAgeSeconds,
        public int $deadCount,
    ) {
        if (null !== $oldestDispatchableAgeSeconds && $oldestDispatchableAgeSeconds < 0) {
            throw new \InvalidArgumentException('Oldest outbox age cannot be negative.');
        }
        if ($deadCount < 0) {
            throw new \InvalidArgumentException('Dead outbox count cannot be negative.');
        }
    }

    public function isHealthy(int $maximumAgeSeconds): bool
    {
        return 0 === $this->deadCount
            && (null === $this->oldestDispatchableAgeSeconds || $this->oldestDispatchableAgeSeconds <= $maximumAgeSeconds);
    }
}
