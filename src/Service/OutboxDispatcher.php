<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\OutboxMessage;
use App\Outbox\OutboxDispatchReport;
use App\Outbox\OutboxMessageHandlerInterface;
use App\Outbox\PermanentOutboxFailure;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class OutboxDispatcher
{
    /** @var list<OutboxMessageHandlerInterface> */
    private array $handlers;

    /** @param iterable<OutboxMessageHandlerInterface> $handlers */
    public function __construct(
        private OutboxService $outboxService,
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

    public function dispatchBatch(int $limit): int
    {
        return $this->dispatchBatchReport($limit)->dispatched;
    }

    public function dispatchBatchReport(int $limit): OutboxDispatchReport
    {
        $messages = $this->outboxService->claimBatch($limit);
        $dispatched = 0;
        $retryScheduled = 0;
        $dead = 0;

        foreach ($messages as $message) {
            $this->dispatch($message);
            match ($message->status()) {
                \App\Enum\OutboxMessageStatus::Dispatched => ++$dispatched,
                \App\Enum\OutboxMessageStatus::Failed => ++$retryScheduled,
                \App\Enum\OutboxMessageStatus::Dead => ++$dead,
                default => throw new \LogicException('Dispatch left an outbox message in an invalid terminal state.'),
            };
        }

        return new OutboxDispatchReport(count($messages), $dispatched, $retryScheduled, $dead);
    }

    public function dispatch(OutboxMessage $message): bool
    {
        try {
            $this->handlerFor($message)->handle($message);
            $this->outboxService->markDispatched($message);

            return true;
        } catch (PermanentOutboxFailure $exception) {
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

    private function handlerFor(OutboxMessage $message): OutboxMessageHandlerInterface
    {
        $matching = array_values(array_filter(
            $this->handlers,
            static fn (OutboxMessageHandlerInterface $handler): bool => $handler->supports($message->messageType()),
        ));

        if ([] === $matching) {
            throw new PermanentOutboxFailure(sprintf('No outbox handler supports message type "%s".', $message->messageType()));
        }
        if (1 !== count($matching)) {
            throw new PermanentOutboxFailure(sprintf('Multiple outbox handlers support message type "%s".', $message->messageType()));
        }

        return $matching[0];
    }

    private function nextAvailableAt(OutboxMessage $message): \DateTimeImmutable
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
