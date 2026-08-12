<?php

declare(strict_types=1);

namespace App\Walleting\Tests\Messenger;

use App\Walleting\Entity\LedgerTransaction;
use App\Walleting\Entity\OutboxMessage;
use App\Walleting\Enum\TransactionType;
use App\Walleting\Message\OutboxEvent;
use App\Walleting\Outbox\MessengerOutboxMessageHandler;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class OutboxDeliveryContractTest extends KernelTestCase
{
    public function testOutboxMessageIsExportedThroughConfiguredSerializedMessengerTransport(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $transport = $container->get('messenger.transport.outbox_events');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $transport->reset();

        $transaction = new LedgerTransaction(TransactionType::Transfer, 'delivery-contract');
        $message = new OutboxMessage(
            'ledger.transaction.posted',
            'ledger.transaction.posted:delivery-contract',
            ['transaction_type' => 'transfer', 'metadata' => ['correlation_id' => 'corr-delivery']],
            ledgerTransaction: $transaction,
        );

        $bus = $container->get('messenger.default_bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        (new MessengerOutboxMessageHandler($bus))->handle($message);

        $sent = $transport->getSent();
        self::assertCount(1, $sent);
        $event = $sent[0]->getMessage();
        self::assertInstanceOf(OutboxEvent::class, $event);
        self::assertSame($message->id()->toRfc4122(), $event->messageId);
        self::assertSame('ledger.transaction.posted', $event->type);
        self::assertSame('ledger.transaction.posted:delivery-contract', $event->deduplicationKey);
        self::assertSame($transaction->id()->toRfc4122(), $event->ledgerTransactionId);
        self::assertSame('corr-delivery', $event->correlationId);
    }
}
