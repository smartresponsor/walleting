<?php

declare(strict_types=1);

namespace App\Walleting\Service;

use App\Walleting\ValueObject\Posting\WalletPostingSloTransitionNotification;
use Psr\Log\LoggerInterface;

final readonly class WalletLoggingPostingSloTransitionNotifier implements WalletPostingSloTransitionNotifierInterface
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function notify(WalletPostingSloTransitionNotification $notification): void
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
