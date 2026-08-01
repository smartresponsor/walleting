<?php

declare(strict_types=1);

namespace App\Enum;

enum ProviderEventStatus: string
{
    case Received = 'received';
    case Processed = 'processed';
    case Failed = 'failed';
}
