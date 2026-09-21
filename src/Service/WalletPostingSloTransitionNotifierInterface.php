<?php

declare(strict_types=1);

namespace App\Walleting\Service;

use App\Walleting\ValueObject\Posting\WalletPostingSloTransitionNotification;

interface WalletPostingSloTransitionNotifierInterface
{
    public function notify(WalletPostingSloTransitionNotification $notification): void;
}
