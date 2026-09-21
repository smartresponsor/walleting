<?php

declare(strict_types=1);

namespace App\Walleting\Enum;

enum WalletOutboxMessageStatus: string
{
    case Pending = 'pending';
    case Claimed = 'claimed';
    case Dispatched = 'dispatched';
    case Failed = 'failed';
    case Dead = 'dead';
}
