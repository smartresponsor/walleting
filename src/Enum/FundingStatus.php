<?php

declare(strict_types=1);

namespace App\Enum;

enum FundingStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Reversed = 'reversed';
}
