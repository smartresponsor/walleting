<?php

declare(strict_types=1);

namespace App\Service;

use App\Ledger\FinancialPostingRequest;

interface PostingExecutorInterface
{
    public function execute(string $idempotencyKey, FinancialPostingRequest $request): string;
}
