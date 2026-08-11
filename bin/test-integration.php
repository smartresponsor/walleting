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

$runWithRetry = static function (string $command, int $attempts = 5, int $delaySeconds = 2): void {
    if ($attempts < 1 || $delaySeconds < 0) {
        throw new InvalidArgumentException('Command retry configuration is invalid.');
    }

    $lastExitCode = 0;
    for ($attempt = 1; $attempt <= $attempts; ++$attempt) {
        passthru($command, $lastExitCode);
        if (0 === $lastExitCode) {
            return;
        }
        if ($attempt < $attempts) {
            fwrite(STDERR, sprintf("Command attempt %d/%d failed with exit code %d; retrying in %d seconds.\n", $attempt, $attempts, $lastExitCode, $delaySeconds));
            if ($delaySeconds > 0) {
                sleep($delaySeconds);
            }
        }
    }

    throw new RuntimeException(sprintf('Command failed after %d attempts with exit code %d: %s', $attempts, $lastExitCode, $command));
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

$portSocket = @stream_socket_server('tcp://127.0.0.1:0', $socketErrorCode, $socketErrorMessage);
if (false === $portSocket) {
    throw new RuntimeException(sprintf('Could not allocate PostgreSQL test host port: %s', $socketErrorMessage));
}
$socketName = stream_socket_get_name($portSocket, false);
fclose($portSocket);
if (false === $socketName || 1 !== preg_match('/:(\d+)$/', $socketName, $matches)) {
    throw new RuntimeException('Could not resolve allocated PostgreSQL test host port.');
}
$port = (int) $matches[1];
putenv('POSTGRES_TEST_HOST_PORT='.$port);
$_ENV['POSTGRES_TEST_HOST_PORT'] = $_SERVER['POSTGRES_TEST_HOST_PORT'] = (string) $port;

try {
    $runWithRetry('docker compose -f compose.test.yaml up -d --wait database-test');

    $waitForPostgreSql($port);

    $databaseUrl = sprintf('postgresql://walleting:walleting@127.0.0.1:%d/walleting?serverVersion=16&charset=utf8', $port);
    putenv('DATABASE_URL='.$databaseUrl);
    putenv('APP_ENV=test');
    putenv('APP_DEBUG=0');
    $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = $databaseUrl;
    $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'test';
    $_ENV['APP_DEBUG'] = $_SERVER['APP_DEBUG'] = '0';
    $run($php.' bin/console cache:clear --env=test --no-debug');
    $runWithRetry($php.' bin/console doctrine:migrations:migrate --no-interaction --env=test');
    $runWithRetry($php.' bin/console doctrine:migrations:status --env=test');

    $productionSmokeDatabaseUrl = sprintf('postgresql://walleting:walleting@127.0.0.1:%d/walleting_test?serverVersion=16&charset=utf8', $port);
    putenv('DATABASE_URL='.$productionSmokeDatabaseUrl);
    putenv('APP_ENV=prod');
    putenv('APP_DEBUG=0');
    putenv('APP_SECRET=walleting-integration-production-smoke');
    putenv('MESSENGER_TRANSPORT_DSN=doctrine://default?queue_name=walleting_outbox_events');
    $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = $productionSmokeDatabaseUrl;
    $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'prod';
    $_ENV['APP_DEBUG'] = $_SERVER['APP_DEBUG'] = '0';
    $_ENV['APP_SECRET'] = $_SERVER['APP_SECRET'] = 'walleting-integration-production-smoke';
    $_ENV['MESSENGER_TRANSPORT_DSN'] = $_SERVER['MESSENGER_TRANSPORT_DSN'] = 'doctrine://default?queue_name=walleting_outbox_events';
    $run($php.' bin/console cache:clear --env=prod --no-debug');
    $run($php.' bin/console walleting:production:check --json --env=prod --no-debug');

    putenv('DATABASE_URL='.$databaseUrl);
    putenv('APP_ENV=test');
    putenv('APP_DEBUG=0');
    $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = $databaseUrl;
    $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'test';
    $_ENV['APP_DEBUG'] = $_SERVER['APP_DEBUG'] = '0';
    $run($php.' vendor/bin/phpunit -c phpunit.integration.xml');
} finally {
    passthru('docker compose -f compose.test.yaml down -v --remove-orphans');
}
