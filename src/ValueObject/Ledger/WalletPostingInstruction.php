<?php

declare(strict_types=1);

namespace App\Walleting\ValueObject\Ledger;

use App\Walleting\Entity\WalletAccount;

final readonly class WalletPostingInstruction
{
    public function __construct(public WalletAccount $account, public int $amountMinor)
    {
        if (0 === $amountMinor) {
            throw new \InvalidArgumentException('Posting amount cannot be zero.');
        }
    }
}
