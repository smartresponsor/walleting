<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Account;
use App\Entity\LedgerTransaction;
use App\Entity\OutboxMessage;
use App\Entity\Wallet;
use App\Enum\AccountCategory;
use App\Enum\OutboxMessageStatus;
use App\Enum\TransactionType;
use PHPUnit\Framework\TestCase;

final class OutboxMessageTest extends TestCase
{
    public function testOutboxMessageLifecycleAndCanonicalPayloadHash(): void
    {
        $transaction = $this->transaction();
        $first = new OutboxMessage('ledger.posted', 'ledger-posted-1', ['b' => 2, 'a' => ['y' => 2, 'x' => 1]], $transaction);
        $second = new OutboxMessage('ledger.posted', 'ledger-posted-2', ['a' => ['x' => 1, 'y' => 2], 'b' => 2], $transaction);

        self::assertSame($first->payloadHash(), $second->payloadHash());
        self::assertSame(OutboxMessageStatus::Pending, $first->status());

        $first->claim();
        self::assertSame(OutboxMessageStatus::Claimed, $first->status());
        self::assertSame(1, $first->attemptCount());

        $first->markFailed('temporary transport failure', new \DateTimeImmutable('-1 second'));
        self::assertSame(OutboxMessageStatus::Failed, $first->status());

        $first->claim();
        self::assertSame(2, $first->attemptCount());
        $first->markDispatched();
        self::assertSame(OutboxMessageStatus::Dispatched, $first->status());
    }

    public function testOutboxMessageRequiresFinancialReference(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new OutboxMessage('ledger.posted', 'missing-reference', []);
    }

    public function testClaimedMessageCanBecomeDeadLetter(): void
    {
        $message = new OutboxMessage('ledger.posted', 'dead-letter-1', [], $this->transaction());
        $message->claim();
        $message->markDead('unsupported message type');

        self::assertSame(OutboxMessageStatus::Dead, $message->status());
        self::assertSame('unsupported message type', $message->lastError());
    }

    private function transaction(): LedgerTransaction
    {
        $wallet = new Wallet('vendor', 'outbox-vendor');
        $cash = new Account($wallet, 'cash', 'USD', AccountCategory::Asset);
        $clearing = new Account($wallet, 'clearing', 'USD', AccountCategory::Clearing);
        $transaction = new LedgerTransaction(TransactionType::Credit, 'outbox-credit-1');
        $transaction->addPosting($cash, 100);
        $transaction->addPosting($clearing, -100);
        $transaction->post();

        return $transaction;
    }
}
