<?php

declare(strict_types=1);

namespace App\Walleting\Service;

use App\Walleting\Entity\WalletAccount;
use App\Walleting\ValueObject\Ledger\WalletFeeAllocation;
use App\Walleting\ValueObject\Ledger\WalletFeePostingPlan;
use App\Walleting\ValueObject\Ledger\WalletPostingInstruction;

final readonly class WalletFeePostingComposer
{
    /** @param list<WalletFeeAllocation> $fees */
    public function compose(WalletAccount $source, WalletAccount $netDestination, int $grossAmountMinor, array $fees): WalletFeePostingPlan
    {
        $currency = $this->settlementCurrency($source, $netDestination, $grossAmountMinor);
        [$feeAmountMinor, $feeMetadata, $feeInstructions] = $this->feePostingData(
            $fees,
            $currency,
            $grossAmountMinor,
            $source,
            $netDestination,
        );
        $netAmountMinor = $grossAmountMinor - $feeAmountMinor;

        return new WalletFeePostingPlan(
            [
                new WalletPostingInstruction($source, -$grossAmountMinor),
                new WalletPostingInstruction($netDestination, $netAmountMinor),
                ...$feeInstructions,
            ],
            [
                'gross_amount_minor' => $grossAmountMinor,
                'net_amount_minor' => $netAmountMinor,
                'fee_amount_minor' => $feeAmountMinor,
                'fees' => $feeMetadata,
            ],
            $grossAmountMinor,
            $netAmountMinor,
            $feeAmountMinor,
        );
    }

    private function settlementCurrency(WalletAccount $source, WalletAccount $netDestination, int $grossAmountMinor): string
    {
        if ($grossAmountMinor <= 0) {
            throw new \InvalidArgumentException('Gross settlement amount must be positive.');
        }
        if ($source === $netDestination) {
            throw new \InvalidArgumentException('Settlement source and net destination accounts must differ.');
        }
        $currency = $source->currency();
        if ($netDestination->currency() !== $currency) {
            throw new \InvalidArgumentException('Settlement accounts must use one currency.');
        }

        return $currency;
    }

    /**
     * @param list<WalletFeeAllocation> $fees
     *
     * @return array{int, list<array{code: string, account_id: string, amount_minor: int}>, list<WalletPostingInstruction>}
     */
    private function feePostingData(array $fees, string $currency, int $grossAmountMinor, WalletAccount $source, WalletAccount $netDestination): array
    {
        $feeAmountMinor = 0;
        $codes = [];
        $accounts = [$source->id()->toRfc4122() => true, $netDestination->id()->toRfc4122() => true];
        $feeMetadata = [];
        $instructions = [];

        foreach ($fees as $fee) {
            if (!$fee instanceof WalletFeeAllocation) {
                throw new \InvalidArgumentException('Every fee must be a FeeAllocation.');
            }
            $code = trim($fee->code);
            if (isset($codes[$code])) {
                throw new \InvalidArgumentException('Fee codes must be unique within one settlement.');
            }
            $codes[$code] = true;
            if ($fee->account->currency() !== $currency) {
                throw new \InvalidArgumentException('Fee account currency must match settlement currency.');
            }
            $accountId = $fee->account->id()->toRfc4122();
            if (isset($accounts[$accountId])) {
                throw new \InvalidArgumentException('Settlement source, net destination, and fee accounts must be distinct.');
            }
            $accounts[$accountId] = true;
            $feeAmountMinor += $fee->amountMinor;
            if ($feeAmountMinor >= $grossAmountMinor) {
                throw new \DomainException('Total fees must be lower than the gross settlement amount.');
            }
            $instructions[] = new WalletPostingInstruction($fee->account, $fee->amountMinor);
            $feeMetadata[] = [
                'code' => $code,
                'account_id' => $accountId,
                'amount_minor' => $fee->amountMinor,
            ];
        }

        return [$feeAmountMinor, $feeMetadata, $instructions];
    }
}
