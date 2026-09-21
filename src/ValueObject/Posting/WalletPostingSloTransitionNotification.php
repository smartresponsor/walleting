<?php

declare(strict_types=1);

namespace App\Walleting\ValueObject\Posting;

use App\Walleting\Event\Outbox\WalletOutboxEvent;

final readonly class WalletPostingSloTransitionNotification
{
    /** @param list<string> $reasons */
    public function __construct(
        public string $scope,
        public int $revision,
        public WalletPostingHealthStatus $previousStatus,
        public WalletPostingHealthStatus $currentStatus,
        public array $reasons,
        public \DateTimeImmutable $changedAt,
    ) {
        if ('' === trim($scope) || $revision < 1) {
            throw new \InvalidArgumentException('Posting SLO transition identity is invalid.');
        }
        foreach ($reasons as $reason) {
            if (!is_string($reason) || '' === trim($reason)) {
                throw new \InvalidArgumentException('Posting SLO transition reasons must be non-empty strings.');
            }
        }
    }

    public static function fromEvent(WalletOutboxEvent $event): self
    {
        if ('posting.slo.state.changed' !== $event->type) {
            throw new \InvalidArgumentException('Outbox event is not a posting SLO state transition.');
        }

        $payload = $event->payload;
        $scope = $payload['scope'] ?? null;
        $revision = $payload['revision'] ?? null;
        $previousStatus = $payload['previous_status'] ?? null;
        $currentStatus = $payload['current_status'] ?? null;
        $reasons = $payload['reasons'] ?? null;
        $changedAt = $payload['changed_at'] ?? null;
        if (!is_string($scope) || !is_int($revision) || !is_string($previousStatus) || !is_string($currentStatus) || !is_array($reasons) || !is_string($changedAt)) {
            throw new \InvalidArgumentException('Posting SLO transition payload is invalid.');
        }

        try {
            $changedAtValue = new \DateTimeImmutable($changedAt);
        } catch (\Throwable $exception) {
            throw new \InvalidArgumentException('Posting SLO transition changed_at is invalid.', 0, $exception);
        }

        return new self(
            $scope,
            $revision,
            WalletPostingHealthStatus::from($previousStatus),
            WalletPostingHealthStatus::from($currentStatus),
            array_values($reasons),
            $changedAtValue,
        );
    }
}
