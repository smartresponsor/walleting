<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\InboxReceipt;
use App\Enum\InboxReceiptStatus;
use App\Message\OutboxEvent;
use PHPUnit\Framework\TestCase;

final class InboxReceiptTest extends TestCase
{
    public function testReceiptUsesCanonicalPayloadHashAndProcessedLifecycle(): void
    {
        $first = new InboxReceipt($this->event(['b' => 2, 'a' => ['y' => 2, 'x' => 1]]));
        $second = new InboxReceipt($this->event(['a' => ['x' => 1, 'y' => 2], 'b' => 2]));

        self::assertSame($first->payloadHash(), $second->payloadHash());
        self::assertSame(InboxReceiptStatus::Processing, $first->status());
        self::assertFalse($first->isProcessed());

        $first->markProcessed();
        self::assertSame(InboxReceiptStatus::Processed, $first->status());
        self::assertTrue($first->isProcessed());
        self::assertInstanceOf(\DateTimeImmutable::class, $first->processedAt());
    }

    public function testSameEventAssertionRejectsReboundMessageIdentity(): void
    {
        $receipt = new InboxReceipt($this->event(['amount' => 100]));

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Inbox receipt identity is already bound to different event content.');
        $receipt->assertSameEvent($this->event(['amount' => 200]));
    }

    public function testReceiptRequiresMessageIdentity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new InboxReceipt(new OutboxEvent('', 'wallet.event', 'dedup-1', [], null, null));
    }

    private function event(array $payload): OutboxEvent
    {
        return new OutboxEvent(
            messageId: 'message-1',
            type: 'wallet.funding.succeeded',
            deduplicationKey: 'funding:1',
            payload: $payload,
            ledgerTransactionId: 'ledger-1',
            providerEventExternalId: null,
            occurredAt: '2026-08-07T01:31:00-05:00',
        );
    }
}
