<?php

declare(strict_types=1);

namespace App\Tests\Outbox;

use App\Entity\Account;
use App\Entity\LedgerTransaction;
use App\Entity\OutboxMessage;
use App\Entity\Wallet;
use App\Enum\AccountCategory;
use App\Enum\TransactionType;
use App\Message\OutboxEvent;
use App\Outbox\MessengerOutboxMessageHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class MessengerOutboxMessageHandlerTest extends TestCase
{
    public function testHandlerPublishesTransportNeutralOutboxEvent(): void
    {
        $transaction = $this->transaction();
        $message = new OutboxMessage('wallet.funding.succeeded', 'funding:123', [
            'amount' => 1250,
            'currency' => 'USD',
            'metadata' => ['correlation_id' => 'corr-123', 'causation_id' => 'cause-456'],
        ], $transaction);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(function (object $published) use ($message, $transaction): bool {
                self::assertInstanceOf(OutboxEvent::class, $published);
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
                self::assertSame(OutboxEvent::SCHEMA_VERSION, $published->schemaVersion);
                self::assertSame(OutboxEvent::SOURCE, $published->source);
                self::assertSame($message->createdAt()->format(DATE_ATOM), $published->occurredAt);
                self::assertSame('corr-123', $published->correlationId);
                self::assertSame('cause-456', $published->causationId);

                return true;
            }))
            ->willReturnCallback(static fn (object $published): Envelope => Envelope::wrap($published));

        $handler = new MessengerOutboxMessageHandler($bus);
        self::assertTrue($handler->supports($message->messageType()));
        $handler->handle($message);
    }

    public function testTransportFailurePropagatesWithoutLocalAcknowledgment(): void
    {
        $message = new OutboxMessage(
            'wallet.funding.succeeded',
            'funding:transport-failure',
            ['amount' => 1250, 'currency' => 'USD'],
            $this->transaction(),
        );
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())
            ->method('dispatch')
            ->willThrowException(new \RuntimeException('transport unavailable'));

        $handler = new MessengerOutboxMessageHandler($bus);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('transport unavailable');
        $handler->handle($message);
    }

    private function transaction(): LedgerTransaction
    {
        $wallet = new Wallet('vendor', 'messenger-vendor');
        $cash = new Account($wallet, 'cash', 'USD', AccountCategory::Asset);
        $clearing = new Account($wallet, 'clearing', 'USD', AccountCategory::Clearing);
        $transaction = new LedgerTransaction(TransactionType::Credit, 'messenger-credit');
        $transaction->addPosting($cash, 1250);
        $transaction->addPosting($clearing, -1250);
        $transaction->post();

        return $transaction;
    }
}
