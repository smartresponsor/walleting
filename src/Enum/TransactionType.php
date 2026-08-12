<?php

declare(strict_types=1);

namespace App\Walleting\Enum;

enum TransactionType: string
{
    case Debit = 'debit';
    case Credit = 'credit';
    case Transfer = 'transfer';
    case Reserve = 'reserve';
    case Capture = 'capture';
    case Release = 'release';
    case Reverse = 'reverse';
    case Refund = 'refund';
}
