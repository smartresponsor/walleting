<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Account;
use App\Ledger\FeeAllocation;
use App\Ledger\FeePostingPlan;
use App\Ledger\PostingInstruction;

final readonly class FeePostingComposer
{
    /** @param list<FeeAllocation> $fees */
    public function compose(Account $source, Account $netDestination, int $grossAmountMinor, array $fees): FeePostingPlan
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

        $feeAmountMinor = 0;
        $codes = [];
        $accounts = [$source->id()->toRfc4122() => true, $netDestination->id()->toRfc4122() => true];
        $feeMetadata = [];
        $instructions = [new PostingInstruction($source, -$grossAmountMinor)];

        foreach ($fees as $fee) {
            if (!$fee instanceof FeeAllocation) {
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
            $instructions[] = new PostingInstruction($fee->account, $fee->amountMinor);
            $feeMetadata[] = [
                'code' => $code,
                'account_id' => $accountId,
                'amount_minor' => $fee->amountMinor,
            ];
        }

        $netAmountMinor = $grossAmountMinor - $feeAmountMinor;
        array_splice($instructions, 1, 0, [new PostingInstruction($netDestination, $netAmountMinor)]);

        return new FeePostingPlan(
            $instructions,
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
}
