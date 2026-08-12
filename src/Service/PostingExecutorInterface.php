<?php

declare(strict_types=1);

namespace App\Walleting\Service;

use App\Walleting\Ledger\FinancialPostingRequest;

interface PostingExecutorInterface
{
    public function execute(string $idempotencyKey, FinancialPostingRequest $request): string;
}
