<?php

declare(strict_types=1);

namespace App\Walleting\Enum;

enum ReconciliationRunStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
}
