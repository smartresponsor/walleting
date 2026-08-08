<?php

declare(strict_types=1);

namespace App\Tests\Messenger;

use App\Message\OutboxEvent;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class OutboxTransportSerializationTest extends KernelTestCase
{
    public function testConfiguredOutboxTransportPreservesRetryCountThroughSerialization(): void
    {
        self::bootKernel();
        $transport = self::getContainer()->get('messenger.transport.outbox_events');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $transport->reset();

        $transport->send(new Envelope($this->event('retry'), [new RedeliveryStamp(2)]));

        $sent = $transport->getSent();
        self::assertCount(1, $sent);
        self::assertSame(2, $sent[0]->last(RedeliveryStamp::class)?->getRetryCount());
    }

    public function testConfiguredFailureTransportPreservesFailureMetadataThroughSerialization(): void
    {
        self::bootKernel();
        $transport = self::getContainer()->get('messenger.transport.outbox_failed');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $transport->reset();

        $transport->send(new Envelope($this->event('failed'), [
            new SentToFailureTransportStamp('outbox_events'),
            new RedeliveryStamp(0),
            new ErrorDetailsStamp(\RuntimeException::class, 0, 'consumer failed'),
        ]));

        $sent = $transport->getSent();
        self::assertCount(1, $sent);
        self::assertSame('outbox_events', $sent[0]->last(SentToFailureTransportStamp::class)?->getOriginalReceiverName());
        self::assertSame(0, $sent[0]->last(RedeliveryStamp::class)?->getRetryCount());
        self::assertSame('consumer failed', $sent[0]->last(ErrorDetailsStamp::class)?->getExceptionMessage());
    }

    private function event(string $suffix): OutboxEvent
    {
        return new OutboxEvent(
            messageId: '0198-transport-'.$suffix,
            type: 'posting.slo.state.changed',
            deduplicationKey: 'posting.slo.state.changed:default:'.$suffix,
            payload: ['scope' => 'default'],
            ledgerTransactionId: null,
            providerEventExternalId: null,
        );
    }
}
