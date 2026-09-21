<?php

declare(strict_types=1);

namespace App\Walleting\Enum;

enum WalletReconciliationRunStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
}
