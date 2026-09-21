<?php

declare(strict_types=1);

namespace App\Walleting\Tests\Messenger;

use App\Walleting\Entity\WalletLedgerTransaction;
use App\Walleting\Entity\WalletOutboxMessage;
use App\Walleting\Enum\WalletTransactionType;
use App\Walleting\Event\Outbox\WalletOutboxEvent;
use App\Walleting\Handler\Outbox\WalletMessengerOutboxMessageHandler;
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

        $transaction = new WalletLedgerTransaction(WalletTransactionType::Transfer, 'delivery-contract');
        $message = new WalletOutboxMessage(
            'ledger.transaction.posted',
            'ledger.transaction.posted:delivery-contract',
            ['transaction_type' => 'transfer', 'metadata' => ['correlation_id' => 'corr-delivery']],
            ledgerTransaction: $transaction,
        );

        $bus = $container->get('messenger.default_bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        (new WalletMessengerOutboxMessageHandler($bus))->handle($message);

        $sent = $transport->getSent();
        self::assertCount(1, $sent);
        $event = $sent[0]->getMessage();
        self::assertInstanceOf(WalletOutboxEvent::class, $event);
        self::assertSame($message->id()->toRfc4122(), $event->messageId);
        self::assertSame('ledger.transaction.posted', $event->type);
        self::assertSame('ledger.transaction.posted:delivery-contract', $event->deduplicationKey);
        self::assertSame($transaction->id()->toRfc4122(), $event->ledgerTransactionId);
        self::assertSame('corr-delivery', $event->correlationId);
    }
}
