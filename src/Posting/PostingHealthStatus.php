<?php

declare(strict_types=1);

namespace App\Walleting\Posting;

enum PostingHealthStatus: string
{
    case Healthy = 'healthy';
    case Degraded = 'degraded';
    case Critical = 'critical';
}
