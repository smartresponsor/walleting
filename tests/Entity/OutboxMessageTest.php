<?php

declare(strict_types=1);

namespace App\Walleting\Tests\Entity;

use App\Walleting\Entity\Wallet;
use App\Walleting\Entity\WalletAccount;
use App\Walleting\Entity\WalletLedgerTransaction;
use App\Walleting\Entity\WalletOutboxMessage;
use App\Walleting\Enum\WalletAccountCategory;
use App\Walleting\Enum\WalletOutboxMessageStatus;
use App\Walleting\Enum\WalletTransactionType;
use PHPUnit\Framework\TestCase;

final class OutboxMessageTest extends TestCase
{
    public function testOutboxMessageLifecycleAndCanonicalPayloadHash(): void
    {
        $transaction = $this->transaction();
        $first = new WalletOutboxMessage('ledger.posted', 'ledger-posted-1', ['b' => 2, 'a' => ['y' => 2, 'x' => 1]], $transaction);
        $second = new WalletOutboxMessage('ledger.posted', 'ledger-posted-2', ['a' => ['x' => 1, 'y' => 2], 'b' => 2], $transaction);

        self::assertSame($first->payloadHash(), $second->payloadHash());
        self::assertSame(WalletOutboxMessageStatus::Pending, $first->status());

        $first->claim();
        self::assertSame(WalletOutboxMessageStatus::Claimed, $first->status());
        self::assertSame(1, $first->attemptCount());

        $first->markFailed('temporary transport failure', new \DateTimeImmutable('-1 second'));
        self::assertSame(WalletOutboxMessageStatus::Failed, $first->status());

        $first->claim();
        self::assertSame(2, $first->attemptCount());
        $first->markDispatched();
        self::assertSame(WalletOutboxMessageStatus::Dispatched, $first->status());
    }

    public function testOutboxMessageRequiresFinancialReference(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new WalletOutboxMessage('ledger.posted', 'missing-reference', []);
    }

    public function testClaimedMessageCanBecomeDeadLetter(): void
    {
        $message = new WalletOutboxMessage('ledger.posted', 'dead-letter-1', [], $this->transaction());
        $message->claim();
        $message->markDead('unsupported message type');

        self::assertSame(WalletOutboxMessageStatus::Dead, $message->status());
        self::assertSame('unsupported message type', $message->lastError());
    }

    private function transaction(): WalletLedgerTransaction
    {
        $wallet = new Wallet('vendor', 'outbox-vendor');
        $cash = new WalletAccount($wallet, 'cash', 'USD', WalletAccountCategory::Asset);
        $clearing = new WalletAccount($wallet, 'clearing', 'USD', WalletAccountCategory::Clearing);
        $transaction = new WalletLedgerTransaction(WalletTransactionType::Credit, 'outbox-credit-1');
        $transaction->addPosting($cash, 100);
        $transaction->addPosting($clearing, -100);
        $transaction->post();

        return $transaction;
    }
}
