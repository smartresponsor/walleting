<?php

declare(strict_types=1);

namespace App\Enum;

enum InboxReceiptStatus: string
{
    case Processing = 'processing';
    case Processed = 'processed';
}
