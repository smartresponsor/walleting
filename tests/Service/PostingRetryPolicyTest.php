<?php

declare(strict_types=1);

namespace App\Walleting\Tests\Service;

use App\Walleting\Service\PostingRetryPolicy;
use Doctrine\DBAL\Driver\Exception as DriverExceptionInterface;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\TestCase;

final class PostingRetryPolicyTest extends TestCase
{
    public function testRetriesDeadlockAndSerializationFailuresWithinAttemptBudget(): void
    {
        $policy = new PostingRetryPolicy(maxAttempts: 3, baseDelayMilliseconds: 10, maxDelayMilliseconds: 100);

        $deadlock = new DeadlockException($this->driverException('40P01'), null);
        $serialization = new DeadlockException($this->driverException('40001'), null);
        self::assertSame('deadlock', $policy->retryReason($deadlock));
        self::assertSame('serialization_failure', $policy->retryReason($serialization));
        self::assertTrue($policy->shouldRetry($deadlock, 1));
        self::assertTrue($policy->shouldRetry($serialization, 2));
        self::assertFalse($policy->shouldRetry(new DeadlockException($this->driverException('40P01'), null), 3));
    }

    public function testRetriesPostgreSqlLockTimeoutSqlState(): void
    {
        $policy = new PostingRetryPolicy();
        $exception = new DriverException($this->driverException('55P03'), null);

        self::assertSame('lock_timeout', $policy->retryReason($exception));
        self::assertTrue($policy->shouldRetry($exception, 1));
    }

    public function testDoesNotRetryUniqueBusinessOrConnectionLossFailures(): void
    {
        $policy = new PostingRetryPolicy();

        self::assertFalse($policy->shouldRetry(new UniqueConstraintViolationException($this->driverException('23505'), null), 1));
        self::assertFalse($policy->shouldRetry(new \RuntimeException('insufficient available balance'), 1));
        self::assertFalse($policy->shouldRetry(new ConnectionLost($this->driverException('08006'), null), 1));
    }

    public function testBackoffIsBoundedAndExponential(): void
    {
        $policy = new PostingRetryPolicy(maxAttempts: 5, baseDelayMilliseconds: 10, maxDelayMilliseconds: 25);

        self::assertSame(10000, $policy->delayMicroseconds(1));
        self::assertSame(20000, $policy->delayMicroseconds(2));
        self::assertSame(25000, $policy->delayMicroseconds(3));
        self::assertSame(25000, $policy->delayMicroseconds(4));
    }

    private function driverException(string $sqlState): DriverExceptionInterface
    {
        return new class('driver failure', $sqlState) extends \Exception implements DriverExceptionInterface {
            public function __construct(string $message, private readonly string $sqlState)
            {
                parent::__construct($message);
            }

            public function getSQLState(): ?string
            {
                return $this->sqlState;
            }
        };
    }
}
