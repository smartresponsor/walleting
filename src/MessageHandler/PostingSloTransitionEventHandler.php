<?php

declare(strict_types=1);

namespace App\Walleting\MessageHandler;

use App\Walleting\Message\OutboxEvent;
use App\Walleting\Posting\PostingSloTransitionNotification;
use App\Walleting\Service\InboxService;
use App\Walleting\Service\PostingSloTransitionNotifierInterface;
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
