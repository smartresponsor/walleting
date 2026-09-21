<?php

declare(strict_types=1);

use App\Walleting\Kernel;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\Dotenv\Dotenv;

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';

$dotenv = (new Dotenv())->usePutenv();
$dotenv->loadEnv($root.'/.env', 'APP_ENV', 'test');
if (is_file($root.'/.env.test.local')) {
    $dotenv->overload($root.'/.env.test.local');
}

putenv('APP_ENV=test');
putenv('APP_DEBUG=0');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'test';
$_ENV['APP_DEBUG'] = $_SERVER['APP_DEBUG'] = '0';

$kernel = new Kernel('test', false);
$kernel->boot();

try {
    $entityManager = $kernel->getContainer()->get('doctrine.orm.entity_manager');
    if (!$entityManager instanceof EntityManagerInterface) {
        throw new RuntimeException('Doctrine ORM entity manager is unavailable.');
    }

    $sql = (new SchemaTool($entityManager))->getUpdateSchemaSql($entityManager->getMetadataFactory()->getAllMetadata());
    $sql = array_values(array_filter($sql, static fn (string $statement): bool => 'DROP TABLE doctrine_migration_versions' !== $statement));
    if ([] !== $sql) {
        fwrite(STDERR, "Doctrine schema parity failed:\n".implode(";\n", $sql).";\n");
        exit(1);
    }

    fwrite(STDOUT, "Doctrine schema parity is synchronized.\n");
} finally {
    $kernel->shutdown();
}
