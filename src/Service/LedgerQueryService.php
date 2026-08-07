<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Account;
use App\Ledger\LedgerHistoryItem;
use App\Ledger\LedgerHistoryPage;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final readonly class LedgerQueryService
{
    public function __construct(private Connection $connection)
    {
    }

    public function history(Account $account, int $limit = 50, ?string $cursor = null): LedgerHistoryPage
    {
        if ($limit < 1 || $limit > 200) {
            throw new \InvalidArgumentException('Ledger history limit must be between 1 and 200.');
        }

        $parameters = [$account->id()->toRfc4122()];
        $types = [];
        $cursorSql = '';
        if (null !== $cursor) {
            [$postedAt, $postingId] = $this->decodeCursor($cursor);
            $cursorSql = ' AND (lt.posted_at < ? OR (lt.posted_at = ? AND p.id < ?))';
            $parameters[] = $postedAt;
            $parameters[] = $postedAt;
            $parameters[] = $postingId;
        }
        $parameters[] = $limit + 1;
        $types[] = ParameterType::STRING;
        if (null !== $cursor) {
            $types[] = ParameterType::STRING;
            $types[] = ParameterType::STRING;
            $types[] = ParameterType::STRING;
        }
        $types[] = ParameterType::INTEGER;

        $rows = $this->connection->fetchAllAssociative(
            'SELECT p.id AS posting_id, p.amount_minor, p.currency, p.sequence, lt.id AS transaction_id, lt.type AS transaction_type, lt.idempotency_key, lt.posted_at FROM posting p INNER JOIN ledger_transaction lt ON lt.id = p.transaction_id WHERE p.account_id = ? AND lt.status = \'posted\''.$cursorSql.' ORDER BY lt.posted_at DESC, p.id DESC LIMIT ?',
            $parameters,
            $types,
        );

        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }

        $items = array_map(fn (array $row): LedgerHistoryItem => $this->historyItem($row), $rows);
        $nextCursor = null;
        if ($hasMore && [] !== $rows) {
            $last = $rows[array_key_last($rows)];
            $nextCursor = $this->encodeCursor((string) $last['posted_at'], (string) $last['posting_id']);
        }

        return new LedgerHistoryPage($items, $nextCursor);
    }

    public function balanceAt(Account $account, \DateTimeImmutable $at): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COALESCE(SUM(p.amount_minor), 0) FROM posting p INNER JOIN ledger_transaction lt ON lt.id = p.transaction_id WHERE p.account_id = ? AND lt.status = \'posted\' AND lt.posted_at <= ?',
            [$account->id()->toRfc4122(), $at->format('Y-m-d H:i:s')],
        );
    }

    /** @param array<string, mixed> $row */
    private function historyItem(array $row): LedgerHistoryItem
    {
        return new LedgerHistoryItem(
            (string) $row['posting_id'],
            (string) $row['transaction_id'],
            (string) $row['transaction_type'],
            (string) $row['idempotency_key'],
            (int) $row['amount_minor'],
            (string) $row['currency'],
            (int) $row['sequence'],
            new \DateTimeImmutable((string) $row['posted_at']),
        );
    }

    private function encodeCursor(string $postedAt, string $postingId): string
    {
        return rtrim(strtr(base64_encode(json_encode([$postedAt, $postingId], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    /** @return array{string, string} */
    private function decodeCursor(string $cursor): array
    {
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        if (false === $decoded) {
            throw new \InvalidArgumentException('Ledger history cursor is invalid.');
        }

        try {
            $data = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('Ledger history cursor is invalid.');
        }

        if (!is_array($data) || 2 !== count($data) || !is_string($data[0]) || !is_string($data[1]) || '' === trim($data[0]) || '' === trim($data[1])) {
            throw new \InvalidArgumentException('Ledger history cursor is invalid.');
        }

        return [$data[0], $data[1]];
    }
}
