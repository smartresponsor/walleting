<?php

declare(strict_types=1);

namespace App\Walleting\Enum;

enum WalletPaymentInstrumentStatus: string
{
    case Active = 'active';
    case Disabled = 'disabled';
    case Expired = 'expired';
}
