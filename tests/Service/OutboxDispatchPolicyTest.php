<?php

declare(strict_types=1);

namespace App\Walleting\Tests\Service;

use App\Walleting\Entity\WalletOutboxMessage;
use App\Walleting\Exception\Outbox\WalletPermanentOutboxFailure;
use App\Walleting\Handler\Outbox\WalletOutboxMessageHandlerInterface;
use App\Walleting\Policy\Outbox\WalletOutboxRetryPolicy;
use App\Walleting\Resolver\Outbox\WalletOutboxMessageHandlerResolver;
use PHPUnit\Framework\TestCase;

final class OutboxDispatchPolicyTest extends TestCase
{
    public function testRetryPolicyPreservesAttemptBudgetAndBoundedExponentialBackoff(): void
    {
        $policy = new WalletOutboxRetryPolicy(maxAttempts: 4, baseDelaySeconds: 30, maxDelaySeconds: 100);

        self::assertFalse($policy->isExhausted(1));
        self::assertFalse($policy->isExhausted(3));
        self::assertTrue($policy->isExhausted(4));
        self::assertSame(30, $policy->delaySeconds(1));
        self::assertSame(60, $policy->delaySeconds(2));
        self::assertSame(100, $policy->delaySeconds(3));
        self::assertSame(100, $policy->delaySeconds(4));
    }

    public function testHandlerResolverRequiresExactlyOneSupportingHandler(): void
    {
        $matching = $this->handler('event.test');
        $other = $this->handler('event.other');

        self::assertSame($matching, (new WalletOutboxMessageHandlerResolver([$other, $matching]))->resolve('event.test'));

        $this->expectException(WalletPermanentOutboxFailure::class);
        $this->expectExceptionMessage('Multiple outbox handlers support message type "event.test".');
        (new WalletOutboxMessageHandlerResolver([$matching, $this->handler('event.test')]))->resolve('event.test');
    }

    public function testHandlerResolverRejectsMissingHandlerWithStableMessage(): void
    {
        $this->expectException(WalletPermanentOutboxFailure::class);
        $this->expectExceptionMessage('No outbox handler supports message type "event.missing".');

        (new WalletOutboxMessageHandlerResolver([]))->resolve('event.missing');
    }

    private function handler(string $supportedType): WalletOutboxMessageHandlerInterface
    {
        return new class($supportedType) implements WalletOutboxMessageHandlerInterface {
            public function __construct(private readonly string $supportedType)
            {
            }

            public function supports(string $messageType): bool
            {
                return $this->supportedType === $messageType;
            }

            public function handle(WalletOutboxMessage $message): void
            {
            }
        };
    }
}
