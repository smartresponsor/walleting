<?php

declare(strict_types=1);

namespace App\Walleting\Service;

use App\Walleting\Balance\AccountBalanceReconciliation;
use App\Walleting\Balance\AccountBalanceSnapshot;
use App\Walleting\Balance\WalletBalanceSnapshot;
use App\Walleting\Balance\WalletCurrencyBalanceSnapshot;
use App\Walleting\Entity\Account;
use App\Walleting\Entity\Wallet;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final readonly class BalanceReadService
{
    public function __construct(private Connection $connection)
    {
    }

    public function account(Account $account): AccountBalanceSnapshot
    {
        $row = $this->connection->fetchAssociative(
            'SELECT a.currency, COALESCE(ab.balance_minor, 0) AS balance_minor, COALESCE(ab.posting_count, 0) AS posting_count, ab.updated_at FROM account a LEFT JOIN account_balance ab ON ab.account_id = a.id WHERE a.id = ?',
            [$account->id()->toRfc4122()],
        );

        if (false === $row) {
            throw new \RuntimeException('Account balance projection target could not be found.');
        }

        return new AccountBalanceSnapshot(
            $account->id()->toRfc4122(),
            (string) $row['currency'],
            (int) $row['balance_minor'],
            (int) $row['posting_count'],
            null === $row['updated_at'] ? null : new \DateTimeImmutable((string) $row['updated_at']),
        );
    }

    public function walletSnapshot(Wallet $wallet): WalletBalanceSnapshot
    {
        return new WalletBalanceSnapshot($wallet->id()->toRfc4122(), $this->wallet($wallet));
    }

    /** @return list<WalletCurrencyBalanceSnapshot> */
    public function wallet(Wallet $wallet): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT a.currency, COALESCE(SUM(CASE WHEN a.category = 'asset' THEN COALESCE(ab.balance_minor, 0) ELSE 0 END), 0) AS available_minor, COALESCE(SUM(CASE WHEN a.category = 'reserve' THEN COALESCE(ab.balance_minor, 0) ELSE 0 END), 0) AS reserved_minor FROM account a LEFT JOIN account_balance ab ON ab.account_id = a.id WHERE a.wallet_id = ? GROUP BY a.currency ORDER BY a.currency",
            [$wallet->id()->toRfc4122()],
        );

        return array_map(
            static fn (array $row): WalletCurrencyBalanceSnapshot => new WalletCurrencyBalanceSnapshot(
                (string) $row['currency'],
                (int) $row['available_minor'],
                (int) $row['reserved_minor'],
            ),
            $rows,
        );
    }

    public function reconcileAccount(Account $account): AccountBalanceReconciliation
    {
        $row = $this->connection->fetchAssociative(
            'SELECT a.currency, COALESCE(ab.balance_minor, 0) AS projected_balance_minor, COALESCE(ab.posting_count, 0) AS projected_posting_count, COALESCE((SELECT SUM(p.amount_minor) FROM posting p WHERE p.account_id = a.id), 0) AS ledger_balance_minor, (SELECT COUNT(*) FROM posting p WHERE p.account_id = a.id) AS ledger_posting_count FROM account a LEFT JOIN account_balance ab ON ab.account_id = a.id WHERE a.id = ?',
            [$account->id()->toRfc4122()],
        );

        if (false === $row) {
            throw new \RuntimeException('Account reconciliation target could not be found.');
        }

        return $this->reconciliationFromRow($account->id()->toRfc4122(), $row);
    }

    /** @return list<array<string, mixed>> */
    public function reconciliationMismatches(int $limit): array
    {
        if ($limit < 1 || $limit > 500) {
            throw new \InvalidArgumentException('Balance reconciliation limit must be between 1 and 500.');
        }

        return $this->connection->fetchAllAssociative(
            'WITH ledger AS (SELECT a.id AS account_id, a.wallet_id, a.currency, COALESCE(SUM(p.amount_minor), 0) AS ledger_balance_minor, COUNT(p.id) AS ledger_posting_count FROM account a LEFT JOIN posting p ON p.account_id = a.id GROUP BY a.id, a.wallet_id, a.currency) SELECT l.account_id, l.wallet_id, l.currency, COALESCE(ab.balance_minor, 0) AS projected_balance_minor, l.ledger_balance_minor, COALESCE(ab.posting_count, 0) AS projected_posting_count, l.ledger_posting_count FROM ledger l LEFT JOIN account_balance ab ON ab.account_id = l.account_id WHERE COALESCE(ab.balance_minor, 0) <> l.ledger_balance_minor OR COALESCE(ab.posting_count, 0) <> l.ledger_posting_count ORDER BY l.account_id LIMIT ?',
            [$limit],
            [ParameterType::INTEGER],
        );
    }

    /** @param array<string, mixed> $row */
    private function reconciliationFromRow(string $accountId, array $row): AccountBalanceReconciliation
    {
        return new AccountBalanceReconciliation(
            $accountId,
            (string) $row['currency'],
            (int) $row['projected_balance_minor'],
            (int) $row['ledger_balance_minor'],
            (int) $row['projected_posting_count'],
            (int) $row['ledger_posting_count'],
        );
    }
}
