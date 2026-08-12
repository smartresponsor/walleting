<?php

declare(strict_types=1);

namespace App\Walleting\Command;

use App\Walleting\Posting\PostingHealthStatus;
use App\Walleting\Posting\PostingSloPolicy;
use App\Walleting\Service\PostingHealthService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'walleting:posting:health', description: 'Inspect posting execution health over a bounded time window.')]
final class PostingHealthCommand extends Command
{
    private const int EXIT_DEGRADED = 2;

    public function __construct(private readonly PostingHealthService $healthService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('window', null, InputOption::VALUE_REQUIRED, 'Observation window in seconds (60-604800).', '3600')
            ->addOption('min-samples', null, InputOption::VALUE_REQUIRED, 'Minimum terminal executions required for confident SLO evaluation.', '20')
            ->addOption('degraded-retry-rate', null, InputOption::VALUE_REQUIRED, 'Retry rate above this is degraded.', '0.10')
            ->addOption('critical-retry-rate', null, InputOption::VALUE_REQUIRED, 'Retry rate above this is critical.', '0.25')
            ->addOption('degraded-failure-rate', null, InputOption::VALUE_REQUIRED, 'Failure rate above this is degraded.', '0.01')
            ->addOption('critical-failure-rate', null, InputOption::VALUE_REQUIRED, 'Failure rate above this is critical.', '0.05')
            ->addOption('degraded-p95-ms', null, InputOption::VALUE_REQUIRED, 'p95 latency above this is degraded.', '1000')
            ->addOption('critical-p95-ms', null, InputOption::VALUE_REQUIRED, 'p95 latency above this is critical.', '3000')
            ->addOption('degraded-contention', null, InputOption::VALUE_REQUIRED, 'Lock timeout + deadlock count above this is degraded.', '3')
            ->addOption('critical-contention', null, InputOption::VALUE_REQUIRED, 'Lock timeout + deadlock count above this is critical.', '10')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Write machine-readable JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $window = filter_var($input->getOption('window'), FILTER_VALIDATE_INT);
        $minimumSamples = filter_var($input->getOption('min-samples'), FILTER_VALIDATE_INT);
        $degradedP95 = filter_var($input->getOption('degraded-p95-ms'), FILTER_VALIDATE_INT);
        $criticalP95 = filter_var($input->getOption('critical-p95-ms'), FILTER_VALIDATE_INT);
        $degradedContention = filter_var($input->getOption('degraded-contention'), FILTER_VALIDATE_INT);
        $criticalContention = filter_var($input->getOption('critical-contention'), FILTER_VALIDATE_INT);
        $degradedRetryRate = $this->rateOption($input, 'degraded-retry-rate');
        $criticalRetryRate = $this->rateOption($input, 'critical-retry-rate');
        $degradedFailureRate = $this->rateOption($input, 'degraded-failure-rate');
        $criticalFailureRate = $this->rateOption($input, 'critical-failure-rate');
        $json = (bool) $input->getOption('json');

        if (!is_int($window) || $window < 60 || $window > 604800 || !is_int($minimumSamples) || !is_int($degradedP95) || !is_int($criticalP95) || !is_int($degradedContention) || !is_int($criticalContention)) {
            $output->writeln($json ? '{"ok":false,"error":"Invalid posting SLO options."}' : '<error>Invalid posting SLO options.</error>');

            return Command::INVALID;
        }

        try {
            $policy = new PostingSloPolicy(
                $minimumSamples,
                $degradedRetryRate,
                $criticalRetryRate,
                $degradedFailureRate,
                $criticalFailureRate,
                $degradedP95,
                $criticalP95,
                $degradedContention,
                $criticalContention,
            );
            $snapshot = $this->healthService->snapshot($window);
            $assessment = $policy->assess($snapshot);
        } catch (\Throwable $exception) {
            $error = trim($exception->getMessage()) ?: $exception::class;
            $output->writeln($json ? json_encode(['ok' => false, 'error' => $error], JSON_THROW_ON_ERROR) : '<error>'.$error.'</error>');

            return Command::INVALID;
        }

        $data = [
            'ok' => PostingHealthStatus::Healthy === $assessment->status,
            'status' => $assessment->status->value,
            'reasons' => $assessment->reasons,
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
            'contention_count' => $snapshot->lockTimeoutCount + $snapshot->deadlockCount,
            'policy' => [
                'minimum_samples' => $policy->minimumSamples,
                'degraded_retry_rate' => $policy->degradedRetryRate,
                'critical_retry_rate' => $policy->criticalRetryRate,
                'degraded_failure_rate' => $policy->degradedFailureRate,
                'critical_failure_rate' => $policy->criticalFailureRate,
                'degraded_p95_ms' => $policy->degradedP95Milliseconds,
                'critical_p95_ms' => $policy->criticalP95Milliseconds,
                'degraded_contention' => $policy->degradedContentionCount,
                'critical_contention' => $policy->criticalContentionCount,
            ],
        ];

        if ($json) {
            $output->writeln(json_encode($data, JSON_THROW_ON_ERROR));
        } else {
            $io = new SymfonyStyle($input, $output);
            $io->definitionList(
                ['Status' => $assessment->status->value],
                ['Reasons' => [] === $assessment->reasons ? 'none' : implode(', ', $assessment->reasons)],
                ['Window' => $snapshot->windowSeconds.' seconds'],
                ['Executions' => $snapshot->executionCount.' / minimum '.$policy->minimumSamples],
                ['Completed / failed' => $snapshot->completedCount.' / '.$snapshot->failedCount],
                ['Retry rate' => sprintf('%.2f%%', $snapshot->retryRate * 100)],
                ['Failure rate' => sprintf('%.2f%%', $snapshot->failureRate * 100)],
                ['p95 latency' => null === $snapshot->p95LatencyMilliseconds ? 'none' : $snapshot->p95LatencyMilliseconds.' ms'],
                ['Lock timeouts / deadlocks' => $snapshot->lockTimeoutCount.' / '.$snapshot->deadlockCount],
            );
            match ($assessment->status) {
                PostingHealthStatus::Healthy => $io->success('Posting SLO is healthy.'),
                PostingHealthStatus::Degraded => $io->warning('Posting SLO is degraded.'),
                PostingHealthStatus::Critical => $io->error('Posting SLO is critical.'),
            };
        }

        return match ($assessment->status) {
            PostingHealthStatus::Healthy => Command::SUCCESS,
            PostingHealthStatus::Degraded => self::EXIT_DEGRADED,
            PostingHealthStatus::Critical => Command::FAILURE,
        };
    }

    private function rateOption(InputInterface $input, string $name): float
    {
        $value = $input->getOption($name);

        return is_numeric($value) ? (float) $value : -1.0;
    }
}
