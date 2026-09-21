<?php

declare(strict_types=1);

namespace App\Walleting\Service;

use App\Walleting\Entity\WalletOutboxMessage;
use App\Walleting\Exception\Outbox\WalletPermanentOutboxFailure;
use App\Walleting\Handler\Outbox\WalletOutboxMessageHandlerInterface;
use App\Walleting\ValueObject\Outbox\WalletOutboxDispatchReport;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class WalletOutboxDispatcher
{
    /** @var list<WalletOutboxMessageHandlerInterface> */
    private array $handlers;

    /** @param iterable<WalletOutboxMessageHandlerInterface> $handlers */
    public function __construct(
        private WalletOutboxService $outboxService,
        #[AutowireIterator('app.outbox_message_handler')]
        iterable $handlers,
        private int $maxAttempts = 8,
        private int $baseDelaySeconds = 30,
        private int $maxDelaySeconds = 3600,
    ) {
        if ($maxAttempts < 1 || $baseDelaySeconds < 1 || $maxDelaySeconds < $baseDelaySeconds) {
            throw new \InvalidArgumentException('Outbox retry policy is invalid.');
        }

        $this->handlers = is_array($handlers) ? array_values($handlers) : iterator_to_array($handlers, false);
    }

    public function dispatchOneById(string $messageId): WalletOutboxDispatchReport
    {
        $message = $this->outboxService->claimById($messageId);
        $this->dispatch($message);

        return match ($message->status()) {
            \App\Walleting\Enum\WalletOutboxMessageStatus::Dispatched => new WalletOutboxDispatchReport(1, 1, 0, 0),
            \App\Walleting\Enum\WalletOutboxMessageStatus::Failed => new WalletOutboxDispatchReport(1, 0, 1, 0),
            \App\Walleting\Enum\WalletOutboxMessageStatus::Dead => new WalletOutboxDispatchReport(1, 0, 0, 1),
            default => throw new \LogicException('Selected dispatch left an outbox message in an invalid terminal state.'),
        };
    }

    public function dispatchBatch(int $limit): int
    {
        return $this->dispatchBatchReport($limit)->dispatched;
    }

    public function dispatchBatchReport(int $limit): WalletOutboxDispatchReport
    {
        $messages = $this->outboxService->claimBatch($limit);
        $dispatched = 0;
        $retryScheduled = 0;
        $dead = 0;

        foreach ($messages as $message) {
            $this->dispatch($message);
            match ($message->status()) {
                \App\Walleting\Enum\WalletOutboxMessageStatus::Dispatched => ++$dispatched,
                \App\Walleting\Enum\WalletOutboxMessageStatus::Failed => ++$retryScheduled,
                \App\Walleting\Enum\WalletOutboxMessageStatus::Dead => ++$dead,
                default => throw new \LogicException('Dispatch left an outbox message in an invalid terminal state.'),
            };
        }

        return new WalletOutboxDispatchReport(count($messages), $dispatched, $retryScheduled, $dead);
    }

    public function dispatch(WalletOutboxMessage $message): bool
    {
        try {
            $this->handlerFor($message)->handle($message);
            $this->outboxService->markDispatched($message);

            return true;
        } catch (WalletPermanentOutboxFailure $exception) {
            $this->outboxService->markTerminalFailure($message, $this->errorMessage($exception));
        } catch (\Throwable $exception) {
            if ($message->attemptCount() >= $this->maxAttempts) {
                $this->outboxService->markTerminalFailure($message, $this->errorMessage($exception));
            } else {
                $this->outboxService->markFailed($message, $this->errorMessage($exception), $this->nextAvailableAt($message));
            }
        }

        return false;
    }

    private function handlerFor(WalletOutboxMessage $message): WalletOutboxMessageHandlerInterface
    {
        $matching = array_values(array_filter(
            $this->handlers,
            static fn (WalletOutboxMessageHandlerInterface $handler): bool => $handler->supports($message->messageType()),
        ));

        if ([] === $matching) {
            throw new WalletPermanentOutboxFailure(sprintf('No outbox handler supports message type "%s".', $message->messageType()));
        }
        if (1 !== count($matching)) {
            throw new WalletPermanentOutboxFailure(sprintf('Multiple outbox handlers support message type "%s".', $message->messageType()));
        }

        return $matching[0];
    }

    private function nextAvailableAt(WalletOutboxMessage $message): \DateTimeImmutable
    {
        $exponent = max(0, $message->attemptCount() - 1);
        $delay = min($this->maxDelaySeconds, $this->baseDelaySeconds * (2 ** $exponent));

        return new \DateTimeImmutable(sprintf('+%d seconds', $delay));
    }

    private function errorMessage(\Throwable $exception): string
    {
        $message = trim($exception->getMessage());

        return '' === $message ? $exception::class : $message;
    }
}
