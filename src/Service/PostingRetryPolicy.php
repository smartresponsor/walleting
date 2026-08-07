<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\RetryableException;

final readonly class PostingRetryPolicy
{
    public function __construct(
        private int $maxAttempts = 3,
        private int $baseDelayMilliseconds = 25,
        private int $maxDelayMilliseconds = 250,
    ) {
        if ($maxAttempts < 1 || $maxAttempts > 10) {
            throw new \InvalidArgumentException('Posting retry max attempts must be between 1 and 10.');
        }
        if ($baseDelayMilliseconds < 0 || $baseDelayMilliseconds > 1000) {
            throw new \InvalidArgumentException('Posting retry base delay must be between 0 and 1000 milliseconds.');
        }
        if ($maxDelayMilliseconds < $baseDelayMilliseconds || $maxDelayMilliseconds > 5000) {
            throw new \InvalidArgumentException('Posting retry max delay must be between base delay and 5000 milliseconds.');
        }
    }

    public function retryReason(\Throwable $exception): ?string
    {
        if ($exception instanceof DriverException) {
            return match ($exception->getSQLState()) {
                '40001' => 'serialization_failure',
                '40P01' => 'deadlock',
                '55P03' => 'lock_timeout',
                default => $exception instanceof RetryableException ? 'retryable_database_error' : null,
            };
        }

        return $exception instanceof RetryableException ? 'retryable_database_error' : null;
    }

    public function shouldRetry(\Throwable $exception, int $attempt): bool
    {
        if ($attempt < 1 || $attempt >= $this->maxAttempts) {
            return false;
        }

        if ($exception instanceof RetryableException) {
            return true;
        }

        return $exception instanceof DriverException && '55P03' === $exception->getSQLState();
    }

    public function delayMicroseconds(int $attempt): int
    {
        if ($attempt < 1) {
            throw new \InvalidArgumentException('Posting retry attempt must be positive.');
        }

        $delayMilliseconds = min(
            $this->maxDelayMilliseconds,
            $this->baseDelayMilliseconds * (2 ** ($attempt - 1)),
        );

        return $delayMilliseconds * 1000;
    }
}
