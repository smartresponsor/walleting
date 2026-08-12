<?php

declare(strict_types=1);

namespace App\Walleting\Enum;

enum TransactionStatus: string
{
    case Pending = 'pending';
    case Posted = 'posted';
    case Reversed = 'reversed';
}
