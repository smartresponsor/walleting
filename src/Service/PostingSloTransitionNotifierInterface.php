<?php

declare(strict_types=1);

namespace App\Walleting\Service;

use App\Walleting\Posting\PostingSloTransitionNotification;

interface PostingSloTransitionNotifierInterface
{
    public function notify(PostingSloTransitionNotification $notification): void;
}
