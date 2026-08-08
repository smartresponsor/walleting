<?php

declare(strict_types=1);

namespace App\Command;

use App\Posting\PostingHealthStatus;
use App\Posting\PostingSloPolicy;
use App\Posting\PostingSloTrendPolicy;
use App\Service\PostingHealthService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'walleting:posting:slo:trend', description: 'Evaluate posting SLO across short and long burn-rate windows.')]
final class PostingSloTrendCommand extends Command
{
    private const int EXIT_DEGRADED = 2;

    public function __construct(private readonly PostingHealthService $healthService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('short-window', null, InputOption::VALUE_REQUIRED, 'Short observation window in seconds.', '900')
            ->addOption('long-window', null, InputOption::VALUE_REQUIRED, 'Long observation window in seconds.', '14400')
            ->addOption('short-min-samples', null, InputOption::VALUE_REQUIRED, 'Minimum terminal executions in short window.', '10')
            ->addOption('long-min-samples', null, InputOption::VALUE_REQUIRED, 'Minimum terminal executions in long window.', '50')
            ->addOption('critical-short-burn', null, InputOption::VALUE_REQUIRED, 'Critical short-window normalized burn rate.', '5')
            ->addOption('critical-long-burn', null, InputOption::VALUE_REQUIRED, 'Critical long-window normalized burn rate.', '2')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Write machine-readable JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $shortWindow = filter_var($input->getOption('short-window'), FILTER_VALIDATE_INT);
        $longWindow = filter_var($input->getOption('long-window'), FILTER_VALIDATE_INT);
        $shortMinSamples = filter_var($input->getOption('short-min-samples'), FILTER_VALIDATE_INT);
        $longMinSamples = filter_var($input->getOption('long-min-samples'), FILTER_VALIDATE_INT);
        $shortBurn = is_numeric($input->getOption('critical-short-burn')) ? (float) $input->getOption('critical-short-burn') : -1.0;
        $longBurn = is_numeric($input->getOption('critical-long-burn')) ? (float) $input->getOption('critical-long-burn') : -1.0;
        $json = (bool) $input->getOption('json');

        if (!is_int($shortWindow) || !is_int($longWindow) || !is_int($shortMinSamples) || !is_int($longMinSamples) || $shortWindow < 60 || $longWindow > 604800 || $shortWindow >= $longWindow) {
            $output->writeln($json ? '{"ok":false,"error":"Invalid posting SLO trend options."}' : '<error>Invalid posting SLO trend options.</error>');

            return Command::INVALID;
        }

        try {
            $shortPolicy = new PostingSloPolicy(minimumSamples: $shortMinSamples);
            $longPolicy = new PostingSloPolicy(minimumSamples: $longMinSamples);
            $policy = new PostingSloTrendPolicy($shortPolicy, $longPolicy, $shortBurn, $longBurn);
            $short = $this->healthService->snapshot($shortWindow);
            $long = $this->healthService->snapshot($longWindow);
            $assessment = $policy->assess($short, $long);
        } catch (\Throwable $exception) {
            $error = trim($exception->getMessage()) ?: $exception::class;
            $output->writeln($json ? json_encode(['ok' => false, 'error' => $error], JSON_THROW_ON_ERROR) : '<error>'.$error.'</error>');

            return Command::INVALID;
        }

        $data = [
            'ok' => PostingHealthStatus::Healthy === $assessment->status,
            'status' => $assessment->status->value,
            'reasons' => $assessment->reasons,
            'short' => [
                'window_seconds' => $short->windowSeconds,
                'execution_count' => $short->executionCount,
                'status' => $assessment->shortAssessment->status->value,
                'retry_rate' => $short->retryRate,
                'failure_rate' => $short->failureRate,
                'retry_burn_rate' => $assessment->shortRetryBurnRate,
                'failure_burn_rate' => $assessment->shortFailureBurnRate,
            ],
            'long' => [
                'window_seconds' => $long->windowSeconds,
                'execution_count' => $long->executionCount,
                'status' => $assessment->longAssessment->status->value,
                'retry_rate' => $long->retryRate,
                'failure_rate' => $long->failureRate,
                'retry_burn_rate' => $assessment->longRetryBurnRate,
                'failure_burn_rate' => $assessment->longFailureBurnRate,
            ],
            'critical_burn' => ['short' => $policy->criticalShortBurnRate, 'long' => $policy->criticalLongBurnRate],
        ];

        if ($json) {
            $output->writeln(json_encode($data, JSON_THROW_ON_ERROR));
        } else {
            $io = new SymfonyStyle($input, $output);
            $io->definitionList(
                ['Status' => $assessment->status->value],
                ['Reasons' => [] === $assessment->reasons ? 'none' : implode(', ', $assessment->reasons)],
                ['Short window' => $short->windowSeconds.'s / '.$short->executionCount.' executions / '.$assessment->shortAssessment->status->value],
                ['Long window' => $long->windowSeconds.'s / '.$long->executionCount.' executions / '.$assessment->longAssessment->status->value],
                ['Retry burn short / long' => sprintf('%.2fx / %.2fx', $assessment->shortRetryBurnRate, $assessment->longRetryBurnRate)],
                ['Failure burn short / long' => sprintf('%.2fx / %.2fx', $assessment->shortFailureBurnRate, $assessment->longFailureBurnRate)],
            );
            match ($assessment->status) {
                PostingHealthStatus::Healthy => $io->success('Posting SLO trend is healthy.'),
                PostingHealthStatus::Degraded => $io->warning('Posting SLO trend is degraded.'),
                PostingHealthStatus::Critical => $io->error('Posting SLO trend is critical.'),
            };
        }

        return match ($assessment->status) {
            PostingHealthStatus::Healthy => Command::SUCCESS,
            PostingHealthStatus::Degraded => self::EXIT_DEGRADED,
            PostingHealthStatus::Critical => Command::FAILURE,
        };
    }
}
