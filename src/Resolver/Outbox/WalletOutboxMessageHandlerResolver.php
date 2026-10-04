<?php

declare(strict_types=1);

namespace App\Walleting\Resolver\Outbox;

use App\Walleting\Exception\Outbox\WalletPermanentOutboxFailure;
use App\Walleting\Handler\Outbox\WalletOutboxMessageHandlerInterface;

final readonly class WalletOutboxMessageHandlerResolver
{
    /** @var list<WalletOutboxMessageHandlerInterface> */
    private array $handlers;

    /** @param iterable<WalletOutboxMessageHandlerInterface> $handlers */
    public function __construct(iterable $handlers)
    {
        $this->handlers = is_array($handlers) ? array_values($handlers) : iterator_to_array($handlers, false);
    }

    public function resolve(string $messageType): WalletOutboxMessageHandlerInterface
    {
        $matching = array_values(array_filter(
            $this->handlers,
            static fn (WalletOutboxMessageHandlerInterface $handler): bool => $handler->supports($messageType),
        ));

        if ([] === $matching) {
            throw new WalletPermanentOutboxFailure(sprintf('No outbox handler supports message type "%s".', $messageType));
        }
        if (1 !== count($matching)) {
            throw new WalletPermanentOutboxFailure(sprintf('Multiple outbox handlers support message type "%s".', $messageType));
        }

        return $matching[0];
    }
}
