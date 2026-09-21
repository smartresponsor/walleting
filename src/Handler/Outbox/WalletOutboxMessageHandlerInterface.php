<?php

declare(strict_types=1);

namespace App\Walleting\Handler\Outbox;

use App\Walleting\Entity\WalletOutboxMessage;

interface WalletOutboxMessageHandlerInterface
{
    public function supports(string $messageType): bool;

    public function handle(WalletOutboxMessage $message): void;
}
