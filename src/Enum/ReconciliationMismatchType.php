<?php

declare(strict_types=1);

namespace App\Walleting\Enum;

enum ReconciliationMismatchType: string
{
    case MissingLocal = 'missing_local';
    case MissingProvider = 'missing_provider';
    case Amount = 'amount';
    case Currency = 'currency';
    case Status = 'status';
}
