<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Account;
use App\Ledger\StatementActivity;
use App\Ledger\StatementPage;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final readonly class StatementQueryService
{
    public function __construct(private Connection $connection)
    {
    }

    public function statement(
        Account $account,
        int $limit = 50,
        ?string $cursor = null,
        ?\DateTimeImmutable $from = null,
        ?\DateTimeImmutable $to = null,
    ): StatementPage {
        if ($limit < 1 || $limit > 200) {
            throw new \InvalidArgumentException('Statement limit must be between 1 and 200.');
        }
        if (null !== $from && null !== $to && $from > $to) {
            throw new \InvalidArgumentException('Statement from date cannot be later than to date.');
        }

        $filters = [];
        $parameters = [$account->id()->toRfc4122()];
        $types = [ParameterType::STRING];

        if (null !== $from) {
            $filters[] = 'activity.posted_at >= ?';
            $parameters[] = $from->format('Y-m-d H:i:s');
            $types[] = ParameterType::STRING;
        }
        if (null !== $to) {
            $filters[] = 'activity.posted_at <= ?';
            $parameters[] = $to->format('Y-m-d H:i:s');
            $types[] = ParameterType::STRING;
        }
        if (null !== $cursor) {
            [$postedAt, $transactionId] = $this->decodeCursor($cursor);
            $filters[] = '(activity.posted_at < ? OR (activity.posted_at = ? AND activity.transaction_id < ?))';
            $parameters[] = $postedAt;
            $parameters[] = $postedAt;
            $parameters[] = $transactionId;
            $types[] = ParameterType::STRING;
            $types[] = ParameterType::STRING;
            $types[] = ParameterType::STRING;
        }

        $parameters[] = $limit + 1;
        $types[] = ParameterType::INTEGER;
        $where = [] === $filters ? '' : 'WHERE '.implode(' AND ', $filters);

        $sql = <<<'SQL'
WITH account_activity AS (
    SELECT
        lt.id AS transaction_id,
        lt.type AS transaction_type,
        lt.idempotency_key,
        lt.metadata,
        lt.posted_at,
        p.currency,
        SUM(p.amount_minor) AS amount_minor
    FROM ledger_transaction lt
    INNER JOIN posting p ON p.transaction_id = lt.id
    WHERE lt.status = 'posted' AND p.account_id = ?
    GROUP BY lt.id, p.currency
),
activity AS (
    SELECT
        aa.*,
        SUM(aa.amount_minor) OVER (ORDER BY aa.posted_at, aa.transaction_id ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) AS running_balance_minor,
        COALESCE((
            SELECT json_agg(json_build_object(
                'account_id', cp.account_id,
                'code', cp.code,
                'category', cp.category,
                'amount_minor', cp.amount_minor
            ) ORDER BY cp.account_id)
            FROM (
                SELECT a.id AS account_id, a.code, a.category, SUM(p2.amount_minor) AS amount_minor
                FROM posting p2
                INNER JOIN account a ON a.id = p2.account_id
                WHERE p2.transaction_id = aa.transaction_id AND p2.account_id <> ?
                GROUP BY a.id, a.code, a.category
            ) cp
        ), '[]'::json) AS counterparties
    FROM account_activity aa
)
SELECT * FROM activity
__WHERE__
ORDER BY posted_at DESC, transaction_id DESC
LIMIT ?
SQL;
        $sql = str_replace('__WHERE__', $where, $sql);

        array_splice($parameters, 1, 0, [$account->id()->toRfc4122()]);
        array_splice($types, 1, 0, [ParameterType::STRING]);

        $rows = $this->connection->fetchAllAssociative($sql, $parameters, $types);
        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }

        $items = array_map(fn (array $row): StatementActivity => $this->activity($row), $rows);
        $nextCursor = null;
        if ($hasMore && [] !== $rows) {
            $last = $rows[array_key_last($rows)];
            $nextCursor = $this->encodeCursor((string) $last['posted_at'], (string) $last['transaction_id']);
        }

        return new StatementPage($items, $nextCursor);
    }

    /** @param array<string, mixed> $row */
    private function activity(array $row): StatementActivity
    {
        $metadata = is_array($row['metadata']) ? $row['metadata'] : json_decode((string) $row['metadata'], true, 512, JSON_THROW_ON_ERROR);
        $counterparties = is_array($row['counterparties']) ? $row['counterparties'] : json_decode((string) $row['counterparties'], true, 512, JSON_THROW_ON_ERROR);

        return new StatementActivity(
            (string) $row['transaction_id'],
            (string) $row['transaction_type'],
            (string) $row['idempotency_key'],
            (int) $row['amount_minor'],
            (int) $row['running_balance_minor'],
            (string) $row['currency'],
            new \DateTimeImmutable((string) $row['posted_at']),
            is_array($metadata) ? $metadata : [],
            is_array($counterparties) ? $counterparties : [],
        );
    }

    private function encodeCursor(string $postedAt, string $transactionId): string
    {
        return rtrim(strtr(base64_encode(json_encode([$postedAt, $transactionId], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    /** @return array{string, string} */
    private function decodeCursor(string $cursor): array
    {
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        if (false === $decoded) {
            throw new \InvalidArgumentException('Statement cursor is invalid.');
        }

        try {
            $data = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('Statement cursor is invalid.');
        }

        if (!is_array($data) || 2 !== count($data) || !is_string($data[0]) || !is_string($data[1]) || '' === trim($data[0]) || '' === trim($data[1])) {
            throw new \InvalidArgumentException('Statement cursor is invalid.');
        }

        return [$data[0], $data[1]];
    }
}
