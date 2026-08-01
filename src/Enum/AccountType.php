<?php

declare(strict_types=1);

namespace App\Enum;

enum AccountType: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Revenue = 'revenue';
    case Expense = 'expense';
    case Equity = 'equity';
}
