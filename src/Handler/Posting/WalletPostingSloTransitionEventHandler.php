<?php

declare(strict_types=1);

namespace App\Walleting\Handler\Posting;

use App\Walleting\Event\Outbox\WalletOutboxEvent;
use App\Walleting\Service\WalletInboxService;
use App\Walleting\Service\WalletPostingSloTransitionNotifierInterface;
use App\Walleting\ValueObject\Posting\WalletPostingSloTransitionNotification;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class WalletPostingSloTransitionEventHandler
{
    public function __construct(
        private WalletInboxService $inboxService,
        private WalletPostingSloTransitionNotifierInterface $notifier,
    ) {
    }

    public function __invoke(WalletOutboxEvent $event): void
    {
        if ('posting.slo.state.changed' !== $event->type) {
            return;
        }

        $this->inboxService->processOnce($event, function (WalletOutboxEvent $event): void {
            $this->notifier->notify(WalletPostingSloTransitionNotification::fromEvent($event));
        });
    }
}
