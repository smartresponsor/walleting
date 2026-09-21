<?php

declare(strict_types=1);

namespace App\Walleting\Enum;

enum WalletProviderEventStatus: string
{
    case Received = 'received';
    case Processed = 'processed';
    case Failed = 'failed';
}
