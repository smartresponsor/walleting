<?php

declare(strict_types=1);

namespace App\Enum;

enum PaymentInstrumentStatus: string
{
    case Active = 'active';
    case Disabled = 'disabled';
    case Expired = 'expired';
}
