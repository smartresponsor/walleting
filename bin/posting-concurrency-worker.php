<?php

declare(strict_types=1);

use App\Walleting\Entity\WalletAccount;
use App\Walleting\Kernel;
use App\Walleting\ValueObject\Ledger\WalletPostingInstruction;
use App\Walleting\Service\WalletNullPostingTelemetry;
use App\Walleting\Service\WalletOutboxService;
use App\Walleting\Service\WalletPostingDbalExecutor;
use App\Walleting\Policy\Posting\WalletPostingRetryPolicy;
use App\Walleting\Service\WalletPostingService;
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

    $accountA = $entityManager->find(WalletAccount::class, $accountAId);
    $accountB = $entityManager->find(WalletAccount::class, $accountBId);
    if (!$accountA instanceof WalletAccount || !$accountB instanceof WalletAccount) {
        throw new RuntimeException('Posting worker accounts could not be loaded.');
    }

    $outboxService = new WalletOutboxService($entityManager, $connection);
    $retryPolicy = new WalletPostingRetryPolicy(
        maxAttempts: (int) (getenv('WALLETING_POSTING_MAX_ATTEMPTS') ?: 3),
        baseDelayMilliseconds: (int) (getenv('WALLETING_POSTING_BASE_DELAY_MS') ?: 25),
        maxDelayMilliseconds: (int) (getenv('WALLETING_POSTING_MAX_DELAY_MS') ?: 250),
    );
    $service = new WalletPostingService(
        $entityManager,
        $outboxService,
        new WalletPostingDbalExecutor(
            $connection,
            $outboxService,
            $retryPolicy,
            new WalletNullPostingTelemetry(),
            (int) (getenv('WALLETING_POSTING_LOCK_TIMEOUT_MS') ?: 1000),
        ),
    );

    $transaction = $service->transfer($idempotencyKey, [
        new WalletPostingInstruction($accountA, -$amount),
        new WalletPostingInstruction($accountB, $amount),
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
