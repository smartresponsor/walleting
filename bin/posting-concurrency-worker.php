<?php

declare(strict_types=1);

use App\Walleting\Entity\Account;
use App\Kernel;
use App\Ledger\PostingInstruction;
use App\Walleting\Service\NullPostingTelemetry;
use App\Walleting\Service\OutboxService;
use App\Walleting\Service\PostingDbalExecutor;
use App\Walleting\Service\PostingRetryPolicy;
use App\Walleting\Service\PostingService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

require dirname(__DIR__).'/vendor/autoload.php';

if (7 !== $argc) {
    fwrite(STDERR, "Usage: posting-concurrency-worker.php <account-a> <account-b> <amount> <idempotency-key> <barrier-file> <ready-file>\n");
    exit(2);
}

[$script, $accountAId, $accountBId, $amountRaw, $idempotencyKey, $barrierFile, $readyFile] = $argv;
$amount = filter_var($amountRaw, FILTER_VALIDATE_INT);
if (!is_int($amount) || 0 === $amount) {
    fwrite(STDERR, "Amount must be a non-zero integer.\n");
    exit(2);
}

$kernel = new Kernel('test', false);
$kernel->boot();
$container = $kernel->getContainer();
/** @var EntityManagerInterface $entityManager */
$entityManager = $container->get('doctrine.orm.entity_manager');
/** @var Connection $connection */
$connection = $container->get('doctrine.dbal.default_connection');

try {
    if (false === @touch($readyFile)) {
        throw new RuntimeException('Posting worker could not publish readiness signal.');
    }

    $deadline = microtime(true) + 5.0;
    while (!is_file($barrierFile)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Posting worker start barrier timed out.');
        }
        usleep(1000);
    }

    $accountA = $entityManager->find(Account::class, $accountAId);
    $accountB = $entityManager->find(Account::class, $accountBId);
    if (!$accountA instanceof Account || !$accountB instanceof Account) {
        throw new RuntimeException('Posting worker accounts could not be loaded.');
    }

    $outboxService = new OutboxService($entityManager, $connection);
    $retryPolicy = new PostingRetryPolicy(
        maxAttempts: (int) (getenv('WALLETING_POSTING_MAX_ATTEMPTS') ?: 3),
        baseDelayMilliseconds: (int) (getenv('WALLETING_POSTING_BASE_DELAY_MS') ?: 25),
        maxDelayMilliseconds: (int) (getenv('WALLETING_POSTING_MAX_DELAY_MS') ?: 250),
    );
    $service = new PostingService(
        $entityManager,
        $outboxService,
        new PostingDbalExecutor(
            $connection,
            $outboxService,
            $retryPolicy,
            new NullPostingTelemetry(),
            (int) (getenv('WALLETING_POSTING_LOCK_TIMEOUT_MS') ?: 1000),
        ),
    );

    $transaction = $service->transfer($idempotencyKey, [
        new PostingInstruction($accountA, -$amount),
        new PostingInstruction($accountB, $amount),
    ], ['operation' => 'concurrency_transfer']);

    fwrite(STDOUT, json_encode([
        'ok' => true,
        'transaction_id' => $transaction->id()->toRfc4122(),
    ], JSON_THROW_ON_ERROR).PHP_EOL);
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDOUT, json_encode([
        'ok' => false,
        'error' => trim($exception->getMessage()) ?: $exception::class,
        'class' => $exception::class,
    ], JSON_THROW_ON_ERROR).PHP_EOL);
    exit(1);
} finally {
    $kernel->shutdown();
}
