<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\ProviderEvent;
use App\Entity\ReconciliationMismatch;
use App\Entity\ReconciliationRun;
use App\Enum\ProviderEventStatus;
use App\Enum\ReconciliationMismatchStatus;
use App\Enum\ReconciliationMismatchType;
use App\Enum\ReconciliationRunStatus;
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
