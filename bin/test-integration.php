<?php

declare(strict_types=1);

$root = dirname(__DIR__);
chdir($root);

$php = escapeshellarg(PHP_BINARY);

$run = static function (string $command): void {
    passthru($command, $exitCode);
    if (0 !== $exitCode) {
        throw new RuntimeException(sprintf('Command failed with exit code %d: %s', $exitCode, $command));
    }
};

$waitForPostgreSql = static function (int $port, int $timeoutSeconds = 60, int $requiredConsecutiveSuccesses = 3): void {
    if ($port < 1 || $port > 65535 || $timeoutSeconds < 1 || $requiredConsecutiveSuccesses < 1) {
        throw new InvalidArgumentException('PostgreSQL readiness probe configuration is invalid.');
    }

    $deadline = microtime(true) + $timeoutSeconds;
    $consecutiveSuccesses = 0;
    $lastError = 'No connection attempt completed.';
    do {
        try {
            $pdo = new PDO(
                sprintf('pgsql:host=127.0.0.1;port=%d;dbname=walleting_test;connect_timeout=2', $port),
                'walleting',
                'walleting',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
            $value = $pdo->query('SELECT 1')->fetchColumn();
            if ('1' !== (string) $value) {
                throw new RuntimeException('PostgreSQL readiness query returned an unexpected result.');
            }
            ++$consecutiveSuccesses;
            if ($consecutiveSuccesses >= $requiredConsecutiveSuccesses) {
                return;
            }
        } catch (Throwable $exception) {
            $consecutiveSuccesses = 0;
            $lastError = $exception->getMessage();
        } finally {
            $pdo = null;
        }

        usleep(500_000);
    } while (microtime(true) < $deadline);

    throw new RuntimeException(sprintf(
        'PostgreSQL host connection did not become stable within %d seconds: %s',
        $timeoutSeconds,
        $lastError,
    ));
};

try {
    $run('docker compose -f compose.test.yaml up -d --wait database-test');

    exec('docker compose -f compose.test.yaml port database-test 5432', $portOutput, $portExitCode);
    $portLine = trim((string) ($portOutput[array_key_last($portOutput)] ?? ''));
    if (0 !== $portExitCode || 1 !== preg_match('/:(\d+)$/', $portLine, $matches)) {
        throw new RuntimeException('Could not resolve Docker PostgreSQL host port.');
    }

    $port = (int) $matches[1];
    $waitForPostgreSql($port);

    $databaseUrl = sprintf('postgresql://walleting:walleting@127.0.0.1:%d/walleting_test?serverVersion=16&charset=utf8', $port);
    putenv('DATABASE_URL='.$databaseUrl);
    putenv('APP_ENV=test');
    putenv('APP_DEBUG=0');
    $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = $databaseUrl;
    $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'test';
    $_ENV['APP_DEBUG'] = $_SERVER['APP_DEBUG'] = '0';
    $run($php.' bin/console cache:clear --env=test --no-debug');
    $run($php.' bin/console doctrine:migrations:migrate --no-interaction --env=test');
    $run($php.' bin/console doctrine:migrations:status --env=test');
    $run($php.' vendor/bin/phpunit -c phpunit.integration.xml');
} finally {
    passthru('docker compose -f compose.test.yaml down -v --remove-orphans');
}
