<?php

declare(strict_types=1);

namespace App\Service;

final readonly class ReservationView
{
    public function __construct(
        public string $id,
        public string $status,
        public int $amountMinor,
        public int $capturedMinor,
        public int $releasedMinor,
        public int $remainingMinor,
        public string $currency,
    ) {
        if ('' === trim($id) || '' === trim($status) || $amountMinor <= 0 || $capturedMinor < 0 || $releasedMinor < 0 || $remainingMinor < 0 || $capturedMinor + $releasedMinor + $remainingMinor !== $amountMinor || 1 !== preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new \InvalidArgumentException('Reservation view is invalid.');
        }
    }
}
