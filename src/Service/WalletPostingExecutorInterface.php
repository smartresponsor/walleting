<?php

declare(strict_types=1);

namespace App\Walleting\Service;

use App\Walleting\ValueObject\Ledger\WalletFinancialPostingRequest;

interface WalletPostingExecutorInterface
{
    public function execute(string $idempotencyKey, WalletFinancialPostingRequest $request): string;
}
