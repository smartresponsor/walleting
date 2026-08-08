<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Message\OutboxEvent;
use App\Posting\PostingHealthStatus;
use App\Posting\PostingSloTransitionNotification;
use App\Service\LoggingPostingSloTransitionNotifier;
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

        $notification = PostingSloTransitionNotification::fromEvent($event);

        self::assertSame('default', $notification->scope);
        self::assertSame(3, $notification->revision);
        self::assertSame(PostingHealthStatus::Degraded, $notification->previousStatus);
        self::assertSame(PostingHealthStatus::Critical, $notification->currentStatus);
        self::assertSame(['sustained_burn_rate'], $notification->reasons);
    }

    public function testMalformedTransitionPayloadIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PostingSloTransitionNotification::fromEvent($this->event([
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

        (new LoggingPostingSloTransitionNotifier($logger))->notify(new PostingSloTransitionNotification(
            'default',
            4,
            PostingHealthStatus::Degraded,
            PostingHealthStatus::Critical,
            ['sustained_critical'],
            new \DateTimeImmutable('2026-08-08 03:00:00'),
        ));
    }

    /** @param array<string, mixed> $payload */
    private function event(array $payload): OutboxEvent
    {
        return new OutboxEvent(
            messageId: '019c1234-1234-7000-8000-000000000001',
            type: 'posting.slo.state.changed',
            deduplicationKey: 'posting.slo.state.changed:default:3',
            payload: $payload,
            ledgerTransactionId: null,
            providerEventExternalId: null,
        );
    }
}
