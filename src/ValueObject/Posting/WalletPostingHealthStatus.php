<?php

declare(strict_types=1);

namespace App\Walleting\ValueObject\Posting;

enum WalletPostingHealthStatus: string
{
    case Healthy = 'healthy';
    case Degraded = 'degraded';
    case Critical = 'critical';
}
