<?php

declare(strict_types=1);

namespace App\Walleting\Tests\Outbox;

use App\Walleting\Entity\Wallet;
use App\Walleting\Entity\WalletAccount;
use App\Walleting\Entity\WalletLedgerTransaction;
use App\Walleting\Entity\WalletOutboxMessage;
use App\Walleting\Enum\WalletAccountCategory;
use App\Walleting\Enum\WalletTransactionType;
use App\Walleting\Event\Outbox\WalletOutboxEvent;
use App\Walleting\Handler\Outbox\WalletMessengerOutboxMessageHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class MessengerOutboxMessageHandlerTest extends TestCase
{
    public function testHandlerPublishesTransportNeutralOutboxEvent(): void
    {
        $transaction = $this->transaction();
        $message = new WalletOutboxMessage('wallet.funding.succeeded', 'funding:123', [
            'amount' => 1250,
            'currency' => 'USD',
            'metadata' => ['correlation_id' => 'corr-123', 'causation_id' => 'cause-456'],
        ], $transaction);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(function (object $published) use ($message, $transaction): bool {
                self::assertInstanceOf(WalletOutboxEvent::class, $published);
                self::assertSame($message->id()->toRfc4122(), $published->messageId);
                self::assertSame('wallet.funding.succeeded', $published->type);
                self::assertSame('funding:123', $published->deduplicationKey);
                self::assertSame([
                    'amount' => 1250,
                    'currency' => 'USD',
                    'metadata' => ['correlation_id' => 'corr-123', 'causation_id' => 'cause-456'],
                ], $published->payload);
                self::assertSame($transaction->id()->toRfc4122(), $published->ledgerTransactionId);
                self::assertNull($published->providerEventExternalId);
                self::assertSame(WalletOutboxEvent::SCHEMA_VERSION, $published->schemaVersion);
                self::assertSame(WalletOutboxEvent::SOURCE, $published->source);
                self::assertSame($message->createdAt()->format(DATE_ATOM), $published->occurredAt);
                self::assertSame('corr-123', $published->correlationId);
                self::assertSame('cause-456', $published->causationId);

                return true;
            }))
            ->willReturnCallback(static fn (object $published): Envelope => Envelope::wrap($published));

        $handler = new WalletMessengerOutboxMessageHandler($bus);
        self::assertTrue($handler->supports($message->messageType()));
        $handler->handle($message);
    }

    public function testTransportFailurePropagatesWithoutLocalAcknowledgment(): void
    {
        $message = new WalletOutboxMessage(
            'wallet.funding.succeeded',
            'funding:transport-failure',
            ['amount' => 1250, 'currency' => 'USD'],
            $this->transaction(),
        );
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())
            ->method('dispatch')
            ->willThrowException(new \RuntimeException('transport unavailable'));

        $handler = new WalletMessengerOutboxMessageHandler($bus);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('transport unavailable');
        $handler->handle($message);
    }

    private function transaction(): WalletLedgerTransaction
    {
        $wallet = new Wallet('vendor', 'messenger-vendor');
        $cash = new WalletAccount($wallet, 'cash', 'USD', WalletAccountCategory::Asset);
        $clearing = new WalletAccount($wallet, 'clearing', 'USD', WalletAccountCategory::Clearing);
        $transaction = new WalletLedgerTransaction(WalletTransactionType::Credit, 'messenger-credit');
        $transaction->addPosting($cash, 1250);
        $transaction->addPosting($clearing, -1250);
        $transaction->post();

        return $transaction;
    }
}
