<?php

declare(strict_types=1);

namespace App\Walleting\Enum;

enum ReconciliationMismatchStatus: string
{
    case Open = 'open';
    case Resolved = 'resolved';
    case Ignored = 'ignored';
}
