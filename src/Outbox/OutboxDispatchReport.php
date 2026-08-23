<?php

declare(strict_types=1);

namespace App\Walleting\Outbox;

final readonly class OutboxDispatchReport
{
    public function __construct(
        public int $claimed,
        public int $dispatched,
        public int $retryScheduled,
        public int $dead,
    ) {
        if (min($claimed, $dispatched, $retryScheduled, $dead) < 0) {
            throw new \InvalidArgumentException('Outbox dispatch metrics cannot be negative.');
        }
        if ($claimed !== $dispatched + $retryScheduled + $dead) {
            throw new \InvalidArgumentException('Outbox dispatch metrics must account for every claimed message.');
        }
    }

    public function hasFailures(): bool
    {
        return $this->retryScheduled > 0 || $this->dead > 0;
    }
}
