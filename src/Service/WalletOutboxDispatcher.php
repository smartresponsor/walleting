<?php

declare(strict_types=1);

namespace App\Walleting\Service;

use App\Walleting\Entity\WalletOutboxMessage;
use App\Walleting\Exception\Outbox\WalletPermanentOutboxFailure;
use App\Walleting\Handler\Outbox\WalletOutboxMessageHandlerInterface;
use App\Walleting\Policy\Outbox\WalletOutboxRetryPolicy;
use App\Walleting\Resolver\Outbox\WalletOutboxMessageHandlerResolver;
use App\Walleting\ValueObject\Outbox\WalletOutboxDispatchReport;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class WalletOutboxDispatcher
{
    private WalletOutboxMessageHandlerResolver $handlerResolver;
    private WalletOutboxRetryPolicy $retryPolicy;

    /** @param iterable<WalletOutboxMessageHandlerInterface> $handlers */
    public function __construct(
        private WalletOutboxService $outboxService,
        #[AutowireIterator('app.outbox_message_handler')]
        iterable $handlers,
        int $maxAttempts = 8,
        int $baseDelaySeconds = 30,
        int $maxDelaySeconds = 3600,
    ) {
        $this->handlerResolver = new WalletOutboxMessageHandlerResolver($handlers);
        $this->retryPolicy = new WalletOutboxRetryPolicy($maxAttempts, $baseDelaySeconds, $maxDelaySeconds);
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
            $this->handlerResolver->resolve($message->messageType())->handle($message);
            $this->outboxService->markDispatched($message);

            return true;
        } catch (WalletPermanentOutboxFailure $exception) {
            $this->outboxService->markTerminalFailure($message, $this->errorMessage($exception));
        } catch (\Throwable $exception) {
            if ($this->retryPolicy->isExhausted($message->attemptCount())) {
                $this->outboxService->markTerminalFailure($message, $this->errorMessage($exception));
            } else {
                $delaySeconds = $this->retryPolicy->delaySeconds($message->attemptCount());
                $this->outboxService->markFailed(
                    $message,
                    $this->errorMessage($exception),
                    new \DateTimeImmutable(sprintf('+%d seconds', $delaySeconds)),
                );
            }
        }

        return false;
    }

    private function errorMessage(\Throwable $exception): string
    {
        $message = trim($exception->getMessage());

        return '' === $message ? $exception::class : $message;
    }
}
