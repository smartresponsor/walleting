<?php

declare(strict_types=1);

namespace App\Enum;

enum ReservationStatus: string
{
    case Active = 'active';
    case PartiallySettled = 'partially_settled';
    case Settled = 'settled';
    case Captured = 'captured';
    case Released = 'released';
    case Expired = 'expired';
}
