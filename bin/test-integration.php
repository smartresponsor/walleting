<?php

declare(strict_types=1);

$root = dirname(__DIR__);
chdir($root);

require $root.'/vendor/autoload.php';

$dotenv = (new Symfony\Component\Dotenv\Dotenv())->usePutenv();
$dotenv->loadEnv($root.'/.env', 'APP_ENV', 'test');
if (is_file($root.'/.env.test.local')) {
    $dotenv->overload($root.'/.env.test.local');
}

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

$runProcess = static function (array $command, array $environment, int $attempts = 1, int $delaySeconds = 2) use ($root): void {
    if ($attempts < 1 || $delaySeconds < 0 || [] === $command) {
        throw new InvalidArgumentException('Process execution configuration is invalid.');
    }

    $environment['SYMFONY_DOTENV_VARS'] = '';

    $previousEnvironment = [];
    foreach ($environment as $name => $value) {
        $previousEnvironment[$name] = getenv($name);
        putenv($name.'='.$value);
        $_ENV[$name] = $_SERVER[$name] = $value;
    }

    $lastExitCode = 0;
    try {
        for ($attempt = 1; $attempt <= $attempts; ++$attempt) {
            $process = proc_open(
                $command,
                [
                    0 => ['file', 'php://stdin', 'r'],
                    1 => ['file', 'php://stdout', 'w'],
                    2 => ['file', 'php://stderr', 'w'],
                ],
                $pipes,
                $root,
                null,
                ['bypass_shell' => true],
            );
            if (!is_resource($process)) {
                throw new RuntimeException('Could not start integration child process.');
            }

            $lastExitCode = proc_close($process);
            if (0 === $lastExitCode) {
                return;
            }
            if ($attempt < $attempts) {
                fwrite(STDERR, sprintf("Process attempt %d/%d failed with exit code %d; retrying in %d seconds.\n", $attempt, $attempts, $lastExitCode, $delaySeconds));
                if ($delaySeconds > 0) {
                    sleep($delaySeconds);
                }
            }
        }
    } finally {
        foreach ($previousEnvironment as $name => $value) {
            if (false === $value) {
                putenv($name);
                unset($_ENV[$name], $_SERVER[$name]);
                continue;
            }

            putenv($name.'='.$value);
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
    }

    throw new RuntimeException(sprintf('Integration child process failed after %d attempts with exit code %d.', $attempts, $lastExitCode));
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

$localDatabaseUrl = trim((string) getenv('WALLETING_INTEGRATION_DATABASE_URL'));
$useDocker = '' === $localDatabaseUrl;
$port = null;

if ($useDocker) {
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
}

$withDatabaseName = static function (string $url, string $database): string {
    if (1 !== preg_match('#^(postgres(?:ql)?://[^/]+/)[^?]+(\?.*)?$#', $url, $matches)) {
        throw new InvalidArgumentException('Integration DATABASE_URL must be a PostgreSQL URL with an explicit database name.');
    }

    return $matches[1].$database.($matches[2] ?? '');
};

try {
    if ($useDocker) {
        $runWithRetry('docker compose -f compose.test.yaml up -d --wait database-test');
        $waitForPostgreSql((int) $port);
        $databaseUrl = sprintf('postgresql://walleting:walleting@127.0.0.1:%d/walleting?serverVersion=16&charset=utf8', $port);
    } else {
        $databaseUrl = $withDatabaseName($localDatabaseUrl, 'walleting');
        fwrite(STDOUT, "Using local PostgreSQL integration database.\n");
    }

    $testEnvironment = [
        'DATABASE_URL' => $databaseUrl,
        'APP_ENV' => 'test',
        'APP_DEBUG' => '0',
    ];
    $runProcess([PHP_BINARY, '-d', 'variables_order=EGPCS', 'bin/console', 'cache:clear', '--no-debug'], $testEnvironment);
    $runProcess([PHP_BINARY, '-d', 'variables_order=EGPCS', 'bin/console', 'doctrine:migrations:migrate', '--no-interaction'], $testEnvironment, 5);
    $runProcess([PHP_BINARY, '-d', 'variables_order=EGPCS', 'bin/console', 'doctrine:migrations:status'], $testEnvironment, 5);

    $testToken = trim((string) getenv('TEST_TOKEN'));
    $productionSmokeDatabaseUrl = $withDatabaseName($databaseUrl, 'walleting_test'.$testToken);
    $productionEnvironment = [
        'DATABASE_URL' => $productionSmokeDatabaseUrl,
        'APP_ENV' => 'prod',
        'APP_DEBUG' => '0',
        'APP_SECRET' => 'walleting-integration-production-smoke',
        'MESSENGER_TRANSPORT_DSN' => 'doctrine://default?queue_name=walleting_outbox_events',
    ];
    $runProcess([PHP_BINARY, '-d', 'variables_order=EGPCS', 'bin/console', 'cache:clear', '--no-debug'], $productionEnvironment);
    $runProcess([PHP_BINARY, '-d', 'variables_order=EGPCS', 'bin/console', 'walleting:production:check', '--json', '--no-debug'], $productionEnvironment);

    $runProcess([PHP_BINARY, '-d', 'variables_order=EGPCS', 'vendor/bin/phpunit', '-c', 'phpunit.integration.xml'], $testEnvironment);
} finally {
    if ($useDocker) {
        passthru('docker compose -f compose.test.yaml down -v --remove-orphans');
    }
}
