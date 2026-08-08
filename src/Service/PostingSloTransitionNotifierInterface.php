<?php

declare(strict_types=1);

namespace App\Service;

use App\Posting\PostingSloTransitionNotification;

interface PostingSloTransitionNotifierInterface
{
    public function notify(PostingSloTransitionNotification $notification): void;
}
