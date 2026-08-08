<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\OutboxEvent;
use App\Posting\PostingSloTransitionNotification;
use App\Service\InboxService;
use App\Service\PostingSloTransitionNotifierInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class PostingSloTransitionEventHandler
{
    public function __construct(
        private InboxService $inboxService,
        private PostingSloTransitionNotifierInterface $notifier,
    ) {
    }

    public function __invoke(OutboxEvent $event): void
    {
        if ('posting.slo.state.changed' !== $event->type) {
            return;
        }

        $this->inboxService->processOnce($event, function (OutboxEvent $event): void {
            $this->notifier->notify(PostingSloTransitionNotification::fromEvent($event));
        });
    }
}
