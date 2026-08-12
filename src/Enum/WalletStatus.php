<?php

declare(strict_types=1);

namespace App\Walleting\Enum;

enum WalletStatus: string
{
    case Active = 'active';
    case Closed = 'closed';
}
