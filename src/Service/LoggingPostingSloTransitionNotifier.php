<?php

declare(strict_types=1);

namespace App\Service;

use App\Posting\PostingSloTransitionNotification;
use Psr\Log\LoggerInterface;

final readonly class LoggingPostingSloTransitionNotifier implements PostingSloTransitionNotifierInterface
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function notify(PostingSloTransitionNotification $notification): void
    {
        $level = match ($notification->currentStatus->value) {
            'critical' => 'error',
            'degraded' => 'warning',
            default => 'info',
        };

        $this->logger->log($level, 'walleting.posting.slo.state.changed', [
            'scope' => $notification->scope,
            'revision' => $notification->revision,
            'previous_status' => $notification->previousStatus->value,
            'current_status' => $notification->currentStatus->value,
            'reasons' => $notification->reasons,
            'changed_at' => $notification->changedAt->format(DATE_ATOM),
        ]);
    }
}
