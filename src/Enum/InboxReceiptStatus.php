<?php

declare(strict_types=1);

namespace App\Walleting\Enum;

enum InboxReceiptStatus: string
{
    case Processing = 'processing';
    case Processed = 'processed';
}
