<?php

declare(strict_types=1);

namespace App\Walleting\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpKernel\KernelInterface;

#[AsCommand(name: 'walleting:production:check', description: 'Validate Walleting production runtime, environment, database version, and required schema objects.')]
final class ProductionCheckCommand extends Command
{
    private const array REQUIRED_TABLES = [
        'wallet',
        'account',
        'ledger_transaction',
        'posting',
        'account_balance',
        'reservation',
        'funding',
        'withdrawal',
        'provider_event',
        'outbox_message',
        'inbox_receipt',
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly KernelInterface $kernel,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Write machine-readable JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $checks = [];
        $checks['app_env'] = $this->check('prod' === $this->kernel->getEnvironment(), $this->kernel->getEnvironment());
        $checks['php_84_or_newer'] = $this->check(version_compare(PHP_VERSION, '8.4.0', '>='), PHP_VERSION);
        $checks['pdo_pgsql'] = $this->check(extension_loaded('pdo_pgsql'), extension_loaded('pdo_pgsql') ? 'loaded' : 'missing');

        foreach (['APP_SECRET', 'DATABASE_URL', 'MESSENGER_TRANSPORT_DSN'] as $name) {
            $value = $this->environment($name);
            $checks['env_'.$name] = $this->check('' !== $value, '' === $value ? 'missing' : 'configured');
        }

        $messengerDsn = $this->environment('MESSENGER_TRANSPORT_DSN');
        $nonDurableMessengerDsn = '' === $messengerDsn || str_starts_with($messengerDsn, 'in-memory://') || str_starts_with($messengerDsn, 'sync://') || str_starts_with($messengerDsn, 'null://');
        $checks['messenger_durable'] = $this->check(!$nonDurableMessengerDsn, '' === $messengerDsn ? 'missing' : ($nonDurableMessengerDsn ? 'non-durable transport is not production-safe' : 'configured'));

        try {
            $serverVersion = (int) $this->connection->fetchOne('SHOW server_version_num');
            $checks['postgresql_16'] = $this->check($serverVersion >= 160000 && $serverVersion < 170000, (string) $serverVersion);

            foreach (self::REQUIRED_TABLES as $table) {
                $regclass = $this->connection->fetchOne('SELECT to_regclass(?)', ['public.'.$table]);
                $exists = is_string($regclass) && '' !== $regclass;
                $checks['table_'.$table] = $this->check($exists, $exists ? 'present' : 'missing');
            }
        } catch (\Throwable $exception) {
            $checks['database_connection'] = $this->check(false, trim($exception->getMessage()) ?: $exception::class);
        }

        $ok = !in_array(false, array_column($checks, 'ok'), true);
        if ((bool) $input->getOption('json')) {
            $output->writeln(json_encode(['ok' => $ok, 'checks' => $checks], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
        } else {
            $io = new SymfonyStyle($input, $output);
            $rows = [];
            foreach ($checks as $name => $result) {
                $rows[] = [$name, $result['ok'] ? 'OK' : 'FAIL', $result['detail']];
            }
            $io->table(['Check', 'Status', 'Detail'], $rows);
            $ok ? $io->success('Walleting production readiness checks passed.') : $io->error('Walleting production readiness checks failed.');
        }

        return $ok ? Command::SUCCESS : Command::FAILURE;
    }

    /** @return array{ok: bool, detail: string} */
    private function check(bool $ok, string $detail): array
    {
        return ['ok' => $ok, 'detail' => $detail];
    }

    private function environment(string $name): string
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);

        return is_string($value) ? trim($value) : '';
    }
}
