<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\PostingHealthService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'walleting:posting:health', description: 'Inspect posting execution health over a bounded time window.')]
final class PostingHealthCommand extends Command
{
    public function __construct(private readonly PostingHealthService $healthService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('window', null, InputOption::VALUE_REQUIRED, 'Observation window in seconds (60-604800).', '3600')
            ->addOption('max-retry-rate', null, InputOption::VALUE_REQUIRED, 'Maximum healthy retried execution rate (0-1).', '0.10')
            ->addOption('max-failure-rate', null, InputOption::VALUE_REQUIRED, 'Maximum healthy terminal failure rate (0-1).', '0.01')
            ->addOption('max-p95-ms', null, InputOption::VALUE_REQUIRED, 'Maximum healthy p95 total execution latency in milliseconds.', '1000')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Write machine-readable JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $window = filter_var($input->getOption('window'), FILTER_VALIDATE_INT);
        $maxP95 = filter_var($input->getOption('max-p95-ms'), FILTER_VALIDATE_INT);
        $maxRetryRate = is_numeric($input->getOption('max-retry-rate')) ? (float) $input->getOption('max-retry-rate') : -1.0;
        $maxFailureRate = is_numeric($input->getOption('max-failure-rate')) ? (float) $input->getOption('max-failure-rate') : -1.0;
        $json = (bool) $input->getOption('json');

        if (!is_int($window) || $window < 60 || $window > 604800 || !is_int($maxP95) || $maxP95 < 1 || $maxP95 > 600000 || $maxRetryRate < 0 || $maxRetryRate > 1 || $maxFailureRate < 0 || $maxFailureRate > 1) {
            $output->writeln($json ? '{"ok":false,"error":"Invalid posting health thresholds."}' : '<error>Invalid posting health thresholds.</error>');

            return Command::INVALID;
        }

        try {
            $snapshot = $this->healthService->snapshot($window);
        } catch (\Throwable $exception) {
            $error = trim($exception->getMessage()) ?: $exception::class;
            $output->writeln($json ? json_encode(['ok' => false, 'error' => $error], JSON_THROW_ON_ERROR) : '<error>'.$error.'</error>');

            return Command::INVALID;
        }

        $healthy = $snapshot->isHealthy($maxRetryRate, $maxFailureRate, $maxP95);
        $data = [
            'ok' => $healthy,
            'window_seconds' => $snapshot->windowSeconds,
            'execution_count' => $snapshot->executionCount,
            'completed_count' => $snapshot->completedCount,
            'failed_count' => $snapshot->failedCount,
            'retried_execution_count' => $snapshot->retriedExecutionCount,
            'retry_event_count' => $snapshot->retryEventCount,
            'retry_rate' => $snapshot->retryRate,
            'failure_rate' => $snapshot->failureRate,
            'p95_latency_ms' => $snapshot->p95LatencyMilliseconds,
            'lock_timeout_count' => $snapshot->lockTimeoutCount,
            'deadlock_count' => $snapshot->deadlockCount,
            'thresholds' => [
                'max_retry_rate' => $maxRetryRate,
                'max_failure_rate' => $maxFailureRate,
                'max_p95_ms' => $maxP95,
            ],
        ];

        if ($json) {
            $output->writeln(json_encode($data, JSON_THROW_ON_ERROR));
        } else {
            $io = new SymfonyStyle($input, $output);
            $io->definitionList(
                ['Window' => $snapshot->windowSeconds.' seconds'],
                ['Executions' => (string) $snapshot->executionCount],
                ['Completed / failed' => $snapshot->completedCount.' / '.$snapshot->failedCount],
                ['Retry rate' => sprintf('%.2f%%', $snapshot->retryRate * 100)],
                ['Failure rate' => sprintf('%.2f%%', $snapshot->failureRate * 100)],
                ['p95 latency' => null === $snapshot->p95LatencyMilliseconds ? 'none' : $snapshot->p95LatencyMilliseconds.' ms'],
                ['Lock timeouts / deadlocks' => $snapshot->lockTimeoutCount.' / '.$snapshot->deadlockCount],
            );
            $healthy ? $io->success('Posting execution health is within thresholds.') : $io->error('Posting execution health thresholds are exceeded.');
        }

        return $healthy ? Command::SUCCESS : Command::FAILURE;
    }
}
