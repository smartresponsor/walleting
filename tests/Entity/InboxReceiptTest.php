<?php

declare(strict_types=1);

namespace App\Walleting\Tests\Entity;

use App\Walleting\Entity\WalletInboxReceipt;
use App\Walleting\Enum\WalletInboxReceiptStatus;
use App\Walleting\Event\Outbox\WalletOutboxEvent;
use PHPUnit\Framework\TestCase;

final class InboxReceiptTest extends TestCase
{
    public function testReceiptUsesCanonicalPayloadHashAndProcessedLifecycle(): void
    {
        $first = new WalletInboxReceipt($this->event(['b' => 2, 'a' => ['y' => 2, 'x' => 1]]));
        $second = new WalletInboxReceipt($this->event(['a' => ['x' => 1, 'y' => 2], 'b' => 2]));

        self::assertSame($first->payloadHash(), $second->payloadHash());
        self::assertSame(WalletInboxReceiptStatus::Processing, $first->status());
        self::assertFalse($first->isProcessed());

        $first->markProcessed();
        self::assertSame(WalletInboxReceiptStatus::Processed, $first->status());
        self::assertTrue($first->isProcessed());
        self::assertInstanceOf(\DateTimeImmutable::class, $first->processedAt());
    }

    public function testSameEventAssertionRejectsReboundMessageIdentity(): void
    {
        $receipt = new WalletInboxReceipt($this->event(['amount' => 100]));

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Inbox receipt identity is already bound to different event content.');
        $receipt->assertSameEvent($this->event(['amount' => 200]));
    }

    public function testReceiptRequiresMessageIdentity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new WalletInboxReceipt(new WalletOutboxEvent('', 'wallet.event', 'dedup-1', [], null, null));
    }

    private function event(array $payload): WalletOutboxEvent
    {
        return new WalletOutboxEvent(
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
