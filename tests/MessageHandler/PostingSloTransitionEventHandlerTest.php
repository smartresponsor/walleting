<?php

declare(strict_types=1);

namespace App\Walleting\Tests\MessageHandler;

use App\Walleting\Event\Outbox\WalletOutboxEvent;
use App\Walleting\Service\WalletLoggingPostingSloTransitionNotifier;
use App\Walleting\ValueObject\Posting\WalletPostingHealthStatus;
use App\Walleting\ValueObject\Posting\WalletPostingSloTransitionNotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class PostingSloTransitionEventHandlerTest extends TestCase
{
    public function testTransitionNotificationDecodesValidOperationalEvent(): void
    {
        $event = $this->event([
            'scope' => 'default',
            'revision' => 3,
            'previous_status' => 'degraded',
            'current_status' => 'critical',
            'reasons' => ['sustained_burn_rate'],
            'changed_at' => '2026-08-08 03:00:00',
        ]);

        $notification = WalletPostingSloTransitionNotification::fromEvent($event);

        self::assertSame('default', $notification->scope);
        self::assertSame(3, $notification->revision);
        self::assertSame(WalletPostingHealthStatus::Degraded, $notification->previousStatus);
        self::assertSame(WalletPostingHealthStatus::Critical, $notification->currentStatus);
        self::assertSame(['sustained_burn_rate'], $notification->reasons);
    }

    public function testMalformedTransitionPayloadIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        WalletPostingSloTransitionNotification::fromEvent($this->event([
            'scope' => 'default',
            'revision' => 0,
            'previous_status' => 'healthy',
            'current_status' => 'critical',
            'reasons' => ['sustained_burn_rate'],
            'changed_at' => '2026-08-08 03:00:00',
        ]));
    }

    public function testLoggingNotifierUsesSeverityOfCurrentState(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('log')
            ->with(
                'error',
                'walleting.posting.slo.state.changed',
                self::callback(static fn (array $context): bool => 'critical' === $context['current_status'] && 4 === $context['revision']),
            );

        (new WalletLoggingPostingSloTransitionNotifier($logger))->notify(new WalletPostingSloTransitionNotification(
            'default',
            4,
            WalletPostingHealthStatus::Degraded,
            WalletPostingHealthStatus::Critical,
            ['sustained_critical'],
            new \DateTimeImmutable('2026-08-08 03:00:00'),
        ));
    }

    /** @param array<string, mixed> $payload */
    private function event(array $payload): WalletOutboxEvent
    {
        return new WalletOutboxEvent(
            messageId: '019c1234-1234-7000-8000-000000000001',
            type: 'posting.slo.state.changed',
            deduplicationKey: 'posting.slo.state.changed:default:3',
            payload: $payload,
            ledgerTransactionId: null,
            providerEventExternalId: null,
        );
    }
}
