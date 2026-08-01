<?php

declare(strict_types=1);

namespace App\Enum;

enum ReservationStatus: string
{
    case Active = 'active';
    case Captured = 'captured';
    case Released = 'released';
    case Expired = 'expired';
}
