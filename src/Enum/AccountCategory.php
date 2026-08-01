<?php

declare(strict_types=1);

namespace App\Enum;

enum AccountCategory: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Revenue = 'revenue';
    case Expense = 'expense';
    case Clearing = 'clearing';
    case Reserve = 'reserve';
}
