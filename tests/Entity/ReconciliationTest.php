<?php

declare(strict_types=1);

namespace App\Walleting\Tests\Entity;

use App\Walleting\Entity\Wallet;
use App\Walleting\Entity\WalletFunding;
use App\Walleting\Entity\WalletPaymentInstrument;
use App\Walleting\Entity\WalletProviderEventEntity;
use App\Walleting\Entity\WalletReconciliationMismatch;
use App\Walleting\Entity\WalletReconciliationRun;
use App\Walleting\Enum\WalletPaymentInstrumentType;
use App\Walleting\Enum\WalletProviderEventStatus;
use App\Walleting\Enum\WalletReconciliationMismatchStatus;
use App\Walleting\Enum\WalletReconciliationMismatchType;
use App\Walleting\Enum\WalletReconciliationRunStatus;
use PHPUnit\Framework\TestCase;

final class ReconciliationTest extends TestCase
{
    public function testProviderEventCanBeProcessedOnlyOnce(): void
    {
        $event = new WalletProviderEventEntity('stripe', 'evt_1', 'payment.succeeded', ['amount' => 1000]);
        $event->markProcessed();
        self::assertSame(WalletProviderEventStatus::Processed, $event->status());
        $this->expectException(\LogicException::class);
        $event->markProcessed();
    }

    public function testProviderEventPayloadHashIsCanonicalAndLinksFunding(): void
    {
        $first = new WalletProviderEventEntity('stripe', 'evt_2', 'payment.succeeded', ['b' => 2, 'a' => ['y' => 2, 'x' => 1]]);
        $second = new WalletProviderEventEntity('stripe', 'evt_2', 'payment.succeeded', ['a' => ['x' => 1, 'y' => 2], 'b' => 2]);
        self::assertSame($first->payloadHash(), $second->payloadHash());

        $wallet = new Wallet('vendor', 'provider-event-vendor');
        $instrument = new WalletPaymentInstrument($wallet, WalletPaymentInstrumentType::Card, 'stripe', 'pm_1', 'Card');
        $funding = new WalletFunding($wallet, $instrument, 1000, 'USD', 'provider-funding-1');
        $first->processFunding($funding);

        self::assertSame(WalletProviderEventStatus::Processed, $first->status());
        self::assertSame($funding, $first->funding());
        $this->expectException(\LogicException::class);
        $first->processFunding($funding);
    }

    public function testReconciliationRunLifecycle(): void
    {
        $run = new WalletReconciliationRun('stripe', '2026-08-01');
        $run->start();
        self::assertSame(WalletReconciliationRunStatus::Running, $run->status());
        $run->complete();
        self::assertSame(WalletReconciliationRunStatus::Completed, $run->status());
    }

    public function testMismatchCanBeResolvedOnlyOnce(): void
    {
        $mismatch = new WalletReconciliationMismatch(new WalletReconciliationRun('stripe', 'run-2'), WalletReconciliationMismatchType::Amount, 'charge_1', ['expected' => 1000, 'actual' => 900]);
        $mismatch->resolve();
        self::assertSame(WalletReconciliationMismatchStatus::Resolved, $mismatch->status());
        $this->expectException(\LogicException::class);
        $mismatch->ignore();
    }
}
