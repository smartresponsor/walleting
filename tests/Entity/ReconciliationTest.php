<?php

declare(strict_types=1);

namespace App\Walleting\Tests\Entity;

use App\Walleting\Entity\Funding;
use App\Walleting\Entity\PaymentInstrument;
use App\Walleting\Entity\ProviderEvent;
use App\Walleting\Entity\ReconciliationMismatch;
use App\Walleting\Entity\ReconciliationRun;
use App\Walleting\Entity\Wallet;
use App\Walleting\Enum\PaymentInstrumentType;
use App\Walleting\Enum\ProviderEventStatus;
use App\Walleting\Enum\ReconciliationMismatchStatus;
use App\Walleting\Enum\ReconciliationMismatchType;
use App\Walleting\Enum\ReconciliationRunStatus;
use PHPUnit\Framework\TestCase;

final class ReconciliationTest extends TestCase
{
    public function testProviderEventCanBeProcessedOnlyOnce(): void
    {
        $event = new ProviderEvent('stripe', 'evt_1', 'payment.succeeded', ['amount' => 1000]);
        $event->markProcessed();
        self::assertSame(ProviderEventStatus::Processed, $event->status());
        $this->expectException(\LogicException::class);
        $event->markProcessed();
    }

    public function testProviderEventPayloadHashIsCanonicalAndLinksFunding(): void
    {
        $first = new ProviderEvent('stripe', 'evt_2', 'payment.succeeded', ['b' => 2, 'a' => ['y' => 2, 'x' => 1]]);
        $second = new ProviderEvent('stripe', 'evt_2', 'payment.succeeded', ['a' => ['x' => 1, 'y' => 2], 'b' => 2]);
        self::assertSame($first->payloadHash(), $second->payloadHash());

        $wallet = new Wallet('vendor', 'provider-event-vendor');
        $instrument = new PaymentInstrument($wallet, PaymentInstrumentType::Card, 'stripe', 'pm_1', 'Card');
        $funding = new Funding($wallet, $instrument, 1000, 'USD', 'provider-funding-1');
        $first->processFunding($funding);

        self::assertSame(ProviderEventStatus::Processed, $first->status());
        self::assertSame($funding, $first->funding());
        $this->expectException(\LogicException::class);
        $first->processFunding($funding);
    }

    public function testReconciliationRunLifecycle(): void
    {
        $run = new ReconciliationRun('stripe', '2026-08-01');
        $run->start();
        self::assertSame(ReconciliationRunStatus::Running, $run->status());
        $run->complete();
        self::assertSame(ReconciliationRunStatus::Completed, $run->status());
    }

    public function testMismatchCanBeResolvedOnlyOnce(): void
    {
        $mismatch = new ReconciliationMismatch(new ReconciliationRun('stripe', 'run-2'), ReconciliationMismatchType::Amount, 'charge_1', ['expected' => 1000, 'actual' => 900]);
        $mismatch->resolve();
        self::assertSame(ReconciliationMismatchStatus::Resolved, $mismatch->status());
        $this->expectException(\LogicException::class);
        $mismatch->ignore();
    }
}
