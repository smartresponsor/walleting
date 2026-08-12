<?php

declare(strict_types=1);

namespace App\Walleting\Outbox;

use App\Walleting\Entity\OutboxMessage;

interface OutboxMessageHandlerInterface
{
    public function supports(string $messageType): bool;

    public function handle(OutboxMessage $message): void;
}
