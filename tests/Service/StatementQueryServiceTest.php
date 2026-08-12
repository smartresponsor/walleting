<?php

declare(strict_types=1);

namespace App\Walleting\Tests\Service;

use App\Walleting\Entity\Account;
use App\Walleting\Entity\Wallet;
use App\Walleting\Enum\AccountCategory;
use App\Walleting\Service\StatementQueryService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class StatementQueryServiceTest extends TestCase
{
    public function testStatementMapsActivityMetadataCounterpartiesAndCursor(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([
            $this->row('tx-3', '2026-08-07 02:03:00', 300, 1300),
            $this->row('tx-2', '2026-08-07 02:02:00', -200, 1000),
            $this->row('tx-1', '2026-08-07 02:01:00', 1000, 1200),
        ]);

        $page = (new StatementQueryService($connection))->statement($this->account(), 2);

        self::assertCount(2, $page->items);
        self::assertSame('tx-3', $page->items[0]->transactionId);
        self::assertSame(300, $page->items[0]->amountMinor);
        self::assertSame(1300, $page->items[0]->runningBalanceMinor);
        self::assertSame('funding', $page->items[0]->operation());
        self::assertSame('clearing', $page->items[0]->counterparties[0]['code']);
        self::assertNotNull($page->nextCursor);
    }

    public function testStatementRejectsInvalidDateRange(): void
    {
        $service = new StatementQueryService($this->createStub(Connection::class));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Statement from date cannot be later than to date.');
        $service->statement(
            $this->account(),
            from: new \DateTimeImmutable('2026-08-08T00:00:00-05:00'),
            to: new \DateTimeImmutable('2026-08-07T00:00:00-05:00'),
        );
    }

    public function testStatementRejectsInvalidCursor(): void
    {
        $service = new StatementQueryService($this->createStub(Connection::class));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Statement cursor is invalid.');
        $service->statement($this->account(), cursor: 'broken%%%');
    }

    /** @return array<string, mixed> */
    private function row(string $transactionId, string $postedAt, int $amountMinor, int $runningBalanceMinor): array
    {
        return [
            'transaction_id' => $transactionId,
            'transaction_type' => 'credit',
            'idempotency_key' => 'key-'.$transactionId,
            'metadata' => json_encode(['operation' => 'funding'], JSON_THROW_ON_ERROR),
            'posted_at' => $postedAt,
            'currency' => 'USD',
            'amount_minor' => $amountMinor,
            'running_balance_minor' => $runningBalanceMinor,
            'counterparties' => json_encode([[
                'account_id' => 'counterparty-1',
                'code' => 'clearing',
                'category' => 'clearing',
                'amount_minor' => -$amountMinor,
            ]], JSON_THROW_ON_ERROR),
        ];
    }

    private function account(): Account
    {
        return new Account(new Wallet('vendor', 'statement-vendor'), 'cash', 'USD', AccountCategory::Asset);
    }
}
