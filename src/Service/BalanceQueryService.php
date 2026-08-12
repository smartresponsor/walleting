<?php

declare(strict_types=1);

namespace App\Walleting\Service;

use App\Walleting\Entity\Account;
use App\Walleting\Entity\Wallet;
use App\Walleting\ReadModel\BalanceSnapshot;
use Doctrine\DBAL\Connection;

final readonly class BalanceQueryService
{
    public function __construct(private Connection $connection)
    {
    }

    public function account(Account $account): int
    {
        $balance = $this->connection->fetchOne(
            'SELECT balance_minor FROM account_balance WHERE account_id = ?',
            [$account->id()->toRfc4122()],
        );

        return false === $balance ? 0 : (int) $balance;
    }

    public function wallet(Wallet $wallet, string $currency): BalanceSnapshot
    {
        $currency = strtoupper(trim($currency));
        if (1 !== preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new \InvalidArgumentException('Currency must be an ISO 4217 alpha-3 code.');
        }

        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT
                    COALESCE(SUM(ab.balance_minor) FILTER (WHERE a.category = 'asset'), 0) AS available_minor,
                    COALESCE(SUM(ab.balance_minor) FILTER (WHERE a.category = 'reserve'), 0) AS reserved_minor,
                    COALESCE(SUM(ab.posting_count) FILTER (WHERE a.category IN ('asset', 'reserve')), 0) AS posting_count,
                    MAX(ab.updated_at) FILTER (WHERE a.category IN ('asset', 'reserve')) AS updated_at
                FROM account a
                LEFT JOIN account_balance ab ON ab.account_id = a.id
                WHERE a.wallet_id = ? AND a.currency = ?
                SQL,
            [$wallet->id()->toRfc4122(), $currency],
        );

        if (false === $row) {
            return new BalanceSnapshot($currency, 0, 0, 0, 0, new \DateTimeImmutable());
        }

        $availableMinor = (int) $row['available_minor'];
        $reservedMinor = (int) $row['reserved_minor'];
        $updatedAt = null === $row['updated_at']
            ? new \DateTimeImmutable()
            : new \DateTimeImmutable((string) $row['updated_at']);

        return new BalanceSnapshot(
            $currency,
            $availableMinor + $reservedMinor,
            $availableMinor,
            $reservedMinor,
            (int) $row['posting_count'],
            $updatedAt,
        );
    }
}
