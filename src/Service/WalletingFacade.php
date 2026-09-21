<?php

declare(strict_types=1);

namespace App\Walleting\Service;

use App\Walleting\Entity\Wallet;
use App\Walleting\Entity\WalletAccount;
use App\Walleting\Entity\WalletFunding;
use App\Walleting\Entity\WalletReservation;
use App\Walleting\Entity\WalletWithdrawal;
use App\Walleting\Snapshot\Balance\WalletBalanceSnapshot;
use App\Walleting\ValueObject\Ledger\WalletLedgerHistoryPage;
use App\Walleting\ValueObject\Ledger\WalletStatementPage;
use App\Walleting\ValueObject\Ledger\WalletTransactionPage;
use Doctrine\DBAL\Connection;

final readonly class WalletingFacade
{
    public function __construct(
        private WalletBalanceReadService $balanceReadService,
        private WalletLedgerQueryService $ledgerQueryService,
        private WalletStatementQueryService $statementQueryService,
        private Connection $connection,
    ) {
    }

    public function walletBalance(Wallet $wallet): WalletBalanceSnapshot
    {
        return $this->balanceReadService->walletSnapshot($wallet);
    }

    public function accountHistory(WalletAccount $account, int $limit = 50, ?string $cursor = null): WalletLedgerHistoryPage
    {
        return $this->ledgerQueryService->history($account, $limit, $cursor);
    }

    public function walletTransactions(Wallet $wallet, int $limit = 50, ?string $cursor = null): WalletTransactionPage
    {
        return $this->ledgerQueryService->walletTransactions($wallet, $limit, $cursor);
    }

    public function accountStatement(WalletAccount $account, int $limit = 50, ?string $cursor = null, ?\DateTimeImmutable $from = null, ?\DateTimeImmutable $to = null): WalletStatementPage
    {
        return $this->statementQueryService->statement($account, $limit, $cursor, $from, $to);
    }

    public function reservation(WalletReservation $reservation): WalletReservationView
    {
        $row = $this->connection->fetchAssociative(
            "SELECT COALESCE(SUM(amount_minor) FILTER (WHERE operation_type = 'capture'), 0) AS captured_minor, COALESCE(SUM(amount_minor) FILTER (WHERE operation_type = 'release'), 0) AS released_minor FROM financial_operation_link WHERE reservation_id = ?",
            [$reservation->id()->toRfc4122()],
        );
        $capturedMinor = false === $row ? 0 : (int) $row['captured_minor'];
        $releasedMinor = false === $row ? 0 : (int) $row['released_minor'];
        $remainingMinor = $reservation->amountMinor() - $capturedMinor - $releasedMinor;
        if ($remainingMinor < 0) {
            throw new \RuntimeException('Reservation settlement projection exceeds the reservation amount.');
        }

        return new WalletReservationView(
            $reservation->id()->toRfc4122(),
            $reservation->status()->value,
            $reservation->amountMinor(),
            $capturedMinor,
            $releasedMinor,
            $remainingMinor,
            $reservation->currency(),
        );
    }

    public function funding(WalletFunding $funding): WalletMoneyOperationView
    {
        return $this->operationView(
            'funding',
            $funding->id()->toRfc4122(),
            $funding->status()->value,
            $funding->amountMinor(),
            $funding->currency(),
            $funding->paymentInstrument()->provider(),
            $funding->paymentInstrument()->providerReference(),
            $funding->providerOperationReference(),
            $funding->transaction()?->id()->toRfc4122(),
            $funding->reversalTransaction()?->id()->toRfc4122(),
        );
    }

    /** @return list<WalletMoneyOperationView> */
    public function fundingByWallet(Wallet $wallet, int $limit = 50): array
    {
        return $this->operationByWallet('funding', $wallet, $limit);
    }

    /** @return list<WalletMoneyOperationView> */
    public function withdrawalByWallet(Wallet $wallet, int $limit = 50): array
    {
        return $this->operationByWallet('withdrawal', $wallet, $limit);
    }

    public function withdrawal(WalletWithdrawal $withdrawal): WalletMoneyOperationView
    {
        return $this->operationView(
            'withdrawal',
            $withdrawal->id()->toRfc4122(),
            $withdrawal->status()->value,
            $withdrawal->amountMinor(),
            $withdrawal->currency(),
            $withdrawal->paymentInstrument()->provider(),
            $withdrawal->paymentInstrument()->providerReference(),
            $withdrawal->providerOperationReference(),
            $withdrawal->transaction()?->id()->toRfc4122(),
            $withdrawal->reversalTransaction()?->id()->toRfc4122(),
        );
    }

    /** @return list<WalletMoneyOperationView> */
    private function operationByWallet(string $type, Wallet $wallet, int $limit): array
    {
        if (!in_array($type, ['funding', 'withdrawal'], true)) {
            throw new \InvalidArgumentException('Money operation type is invalid.');
        }
        if ($limit < 1 || $limit > 200) {
            throw new \InvalidArgumentException('Money operation limit must be between 1 and 200.');
        }

        $rows = $this->connection->fetchAllAssociative(
            sprintf('SELECT o.id, o.status, o.amount_minor, o.currency, pi.provider, pi.provider_reference, o.provider_operation_reference, o.transaction_id, o.reversal_transaction_id FROM %s o INNER JOIN payment_instrument pi ON pi.id = o.payment_instrument_id WHERE o.wallet_id = ? ORDER BY o.created_at DESC, o.id DESC LIMIT %d', $type, $limit),
            [$wallet->id()->toRfc4122()],
        );

        return array_map(fn (array $row): WalletMoneyOperationView => $this->operationView(
            $type,
            (string) $row['id'],
            (string) $row['status'],
            (int) $row['amount_minor'],
            (string) $row['currency'],
            (string) $row['provider'],
            (string) $row['provider_reference'],
            null === $row['provider_operation_reference'] ? null : (string) $row['provider_operation_reference'],
            null === $row['transaction_id'] ? null : (string) $row['transaction_id'],
            null === $row['reversal_transaction_id'] ? null : (string) $row['reversal_transaction_id'],
        ), $rows);
    }

    private function operationView(string $type, string $id, string $status, int $amountMinor, string $currency, string $provider, string $providerReference, ?string $providerOperationReference, ?string $transactionId, ?string $reversalTransactionId): WalletMoneyOperationView
    {
        return new WalletMoneyOperationView($id, $type, $status, $amountMinor, $currency, $provider, $providerReference, $providerOperationReference, $transactionId, $reversalTransactionId);
    }
}
