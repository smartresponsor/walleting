<?php

declare(strict_types=1);

namespace App\Walleting\Service;

use App\Walleting\Balance\WalletBalanceSnapshot;
use App\Walleting\Entity\Account;
use App\Walleting\Entity\Funding;
use App\Walleting\Entity\Reservation;
use App\Walleting\Entity\Wallet;
use App\Walleting\Entity\Withdrawal;
use App\Walleting\Ledger\LedgerHistoryPage;
use App\Walleting\Ledger\StatementPage;
use Doctrine\DBAL\Connection;

final readonly class WalletingFacade
{
    public function __construct(
        private BalanceReadService $balanceReadService,
        private LedgerQueryService $ledgerQueryService,
        private StatementQueryService $statementQueryService,
        private Connection $connection,
    ) {
    }

    public function walletBalance(Wallet $wallet): WalletBalanceSnapshot
    {
        return $this->balanceReadService->walletSnapshot($wallet);
    }

    public function accountHistory(Account $account, int $limit = 50, ?string $cursor = null): LedgerHistoryPage
    {
        return $this->ledgerQueryService->history($account, $limit, $cursor);
    }

    public function accountStatement(Account $account, int $limit = 50, ?string $cursor = null, ?\DateTimeImmutable $from = null, ?\DateTimeImmutable $to = null): StatementPage
    {
        return $this->statementQueryService->statement($account, $limit, $cursor, $from, $to);
    }

    public function reservation(Reservation $reservation): ReservationView
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

        return new ReservationView(
            $reservation->id()->toRfc4122(),
            $reservation->status()->value,
            $reservation->amountMinor(),
            $capturedMinor,
            $releasedMinor,
            $remainingMinor,
            $reservation->currency(),
        );
    }

    public function funding(Funding $funding): MoneyOperationView
    {
        return $this->operationView(
            'funding',
            $funding->id()->toRfc4122(),
            $funding->status()->value,
            $funding->amountMinor(),
            $funding->currency(),
            $funding->paymentInstrument()->provider(),
            $funding->paymentInstrument()->providerReference(),
            $funding->transaction()?->id()->toRfc4122(),
            $funding->reversalTransaction()?->id()->toRfc4122(),
        );
    }

    public function withdrawal(Withdrawal $withdrawal): MoneyOperationView
    {
        return $this->operationView(
            'withdrawal',
            $withdrawal->id()->toRfc4122(),
            $withdrawal->status()->value,
            $withdrawal->amountMinor(),
            $withdrawal->currency(),
            $withdrawal->paymentInstrument()->provider(),
            $withdrawal->paymentInstrument()->providerReference(),
            $withdrawal->transaction()?->id()->toRfc4122(),
            $withdrawal->reversalTransaction()?->id()->toRfc4122(),
        );
    }

    private function operationView(string $type, string $id, string $status, int $amountMinor, string $currency, string $provider, string $providerReference, ?string $transactionId, ?string $reversalTransactionId): MoneyOperationView
    {
        return new MoneyOperationView($id, $type, $status, $amountMinor, $currency, $provider, $providerReference, $transactionId, $reversalTransactionId);
    }
}
