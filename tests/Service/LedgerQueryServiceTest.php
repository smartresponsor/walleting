<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Account;
use App\Entity\Wallet;
use App\Enum\AccountCategory;
use App\Service\LedgerQueryService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class LedgerQueryServiceTest extends TestCase
{
    public function testHistoryReturnsDeterministicNextCursor(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([
            $this->row('posting-3', '2026-08-07 01:02:03', 300),
            $this->row('posting-2', '2026-08-07 01:02:03', 200),
            $this->row('posting-1', '2026-08-07 01:01:00', 100),
        ]);

        $page = (new LedgerQueryService($connection))->history($this->account(), 2);

        self::assertCount(2, $page->items);
        self::assertSame('posting-3', $page->items[0]->postingId);
        self::assertSame('posting-2', $page->items[1]->postingId);
        self::assertNotNull($page->nextCursor);
    }

    public function testHistoryRejectsInvalidCursor(): void
    {
        $service = new LedgerQueryService($this->createStub(Connection::class));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Ledger history cursor is invalid.');
        $service->history($this->account(), 20, 'not-valid-base64%%%');
    }

    public function testBalanceAtReturnsLedgerSum(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('fetchOne')->willReturn('1250');

        $balance = (new LedgerQueryService($connection))->balanceAt($this->account(), new \DateTimeImmutable('2026-08-07T01:30:00-05:00'));

        self::assertSame(1250, $balance);
    }

    /** @return array<string, mixed> */
    private function row(string $postingId, string $postedAt, int $amount): array
    {
        return [
            'posting_id' => $postingId,
            'amount_minor' => $amount,
            'currency' => 'USD',
            'sequence' => 1,
            'transaction_id' => 'transaction-'.$postingId,
            'transaction_type' => 'credit',
            'idempotency_key' => 'key-'.$postingId,
            'posted_at' => $postedAt,
        ];
    }

    private function account(): Account
    {
        return new Account(new Wallet('vendor', 'ledger-query-vendor'), 'cash', 'USD', AccountCategory::Asset);
    }
}
