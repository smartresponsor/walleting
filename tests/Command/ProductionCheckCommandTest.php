<?php

declare(strict_types=1);

namespace App\Walleting\Tests\Command;

use App\Walleting\Command\ProductionCheckCommand;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;

final class ProductionCheckCommandTest extends TestCase
{
    public function testProductionCheckPassesForSupportedRuntimeAndDurableTransport(): void
    {
        $this->withEnvironment([
            'APP_SECRET' => 'secret',
            'DATABASE_URL' => 'postgresql://walleting@example/walleting',
            'MESSENGER_TRANSPORT_DSN' => 'doctrine://default?queue_name=walleting_events',
        ], function (): void {
            $tester = new CommandTester(new ProductionCheckCommand($this->connectionWithCompleteSchema(), $this->prodKernel()));

            self::assertSame(Command::SUCCESS, $tester->execute(['--json' => true]));
            $payload = json_decode(trim($tester->getDisplay()), true, flags: JSON_THROW_ON_ERROR);
            self::assertTrue($payload['ok']);
            self::assertTrue($payload['checks']['messenger_durable']['ok']);
            self::assertTrue($payload['checks']['postgresql_16']['ok']);
        });
    }

    public function testProductionCheckRejectsInMemoryMessengerTransport(): void
    {
        $this->withEnvironment([
            'APP_SECRET' => 'secret',
            'DATABASE_URL' => 'postgresql://walleting@example/walleting',
            'MESSENGER_TRANSPORT_DSN' => 'in-memory://',
        ], function (): void {
            $tester = new CommandTester(new ProductionCheckCommand($this->connectionWithCompleteSchema(), $this->prodKernel()));

            self::assertSame(Command::FAILURE, $tester->execute(['--json' => true]));
            $payload = json_decode(trim($tester->getDisplay()), true, flags: JSON_THROW_ON_ERROR);
            self::assertFalse($payload['ok']);
            self::assertFalse($payload['checks']['messenger_durable']['ok']);
        });
    }

    private function connectionWithCompleteSchema(): Connection
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturnCallback(static function (string $sql, array $params = []): string|int {
            if ('SHOW server_version_num' === $sql) {
                return 160011;
            }
            if ('SELECT to_regclass(?)' === $sql && isset($params[0])) {
                return (string) $params[0];
            }

            throw new \LogicException('Unexpected production-check query.');
        });

        return $connection;
    }

    private function prodKernel(): KernelInterface
    {
        $kernel = $this->createStub(KernelInterface::class);
        $kernel->method('getEnvironment')->willReturn('prod');

        return $kernel;
    }

    /** @param array<string, string> $values */
    private function withEnvironment(array $values, callable $callback): void
    {
        $beforeServer = [];
        $beforeEnv = [];
        foreach ($values as $name => $value) {
            $beforeServer[$name] = $_SERVER[$name] ?? null;
            $beforeEnv[$name] = $_ENV[$name] ?? null;
            $_SERVER[$name] = $value;
            $_ENV[$name] = $value;
        }

        try {
            $callback();
        } finally {
            foreach ($values as $name => $_) {
                if (null === $beforeServer[$name]) {
                    unset($_SERVER[$name]);
                } else {
                    $_SERVER[$name] = $beforeServer[$name];
                }
                if (null === $beforeEnv[$name]) {
                    unset($_ENV[$name]);
                } else {
                    $_ENV[$name] = $beforeEnv[$name];
                }
            }
        }
    }
}
