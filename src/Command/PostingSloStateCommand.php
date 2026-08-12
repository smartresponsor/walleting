<?php

declare(strict_types=1);

namespace App\Walleting\Command;

use App\Walleting\Posting\PostingHealthStatus;
use App\Walleting\Posting\PostingSloPolicy;
use App\Walleting\Posting\PostingSloTrendPolicy;
use App\Walleting\Service\PostingHealthService;
use App\Walleting\Service\PostingSloStateService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'walleting:posting:slo:state', description: 'Evaluate and persist posting SLO alert state with hysteresis.')]
final class PostingSloStateCommand extends Command
{
    private const int EXIT_DEGRADED = 2;

    public function __construct(
        private readonly PostingHealthService $healthService,
        private readonly PostingSloStateService $stateService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('scope', null, InputOption::VALUE_REQUIRED, 'Persistent alert state scope.', 'default')
            ->addOption('short-window', null, InputOption::VALUE_REQUIRED, 'Short observation window in seconds.', '900')
            ->addOption('long-window', null, InputOption::VALUE_REQUIRED, 'Long observation window in seconds.', '14400')
            ->addOption('short-min-samples', null, InputOption::VALUE_REQUIRED, 'Minimum short-window terminal executions.', '10')
            ->addOption('long-min-samples', null, InputOption::VALUE_REQUIRED, 'Minimum long-window terminal executions.', '50')
            ->addOption('critical-short-burn', null, InputOption::VALUE_REQUIRED, 'Critical short-window burn rate.', '5')
            ->addOption('critical-long-burn', null, InputOption::VALUE_REQUIRED, 'Critical long-window burn rate.', '2')
            ->addOption('breach-evaluations', null, InputOption::VALUE_REQUIRED, 'Consecutive worsening evaluations required for state change.', '2')
            ->addOption('recovery-evaluations', null, InputOption::VALUE_REQUIRED, 'Consecutive recovery evaluations required for state change.', '3')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Write machine-readable JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $scope = (string) $input->getOption('scope');
        $shortWindow = filter_var($input->getOption('short-window'), FILTER_VALIDATE_INT);
        $longWindow = filter_var($input->getOption('long-window'), FILTER_VALIDATE_INT);
        $shortMinSamples = filter_var($input->getOption('short-min-samples'), FILTER_VALIDATE_INT);
        $longMinSamples = filter_var($input->getOption('long-min-samples'), FILTER_VALIDATE_INT);
        $breachEvaluations = filter_var($input->getOption('breach-evaluations'), FILTER_VALIDATE_INT);
        $recoveryEvaluations = filter_var($input->getOption('recovery-evaluations'), FILTER_VALIDATE_INT);
        $shortBurn = is_numeric($input->getOption('critical-short-burn')) ? (float) $input->getOption('critical-short-burn') : -1.0;
        $longBurn = is_numeric($input->getOption('critical-long-burn')) ? (float) $input->getOption('critical-long-burn') : -1.0;
        $json = (bool) $input->getOption('json');

        if (!is_int($shortWindow) || !is_int($longWindow) || !is_int($shortMinSamples) || !is_int($longMinSamples) || !is_int($breachEvaluations) || !is_int($recoveryEvaluations) || $shortWindow < 60 || $longWindow > 604800 || $shortWindow >= $longWindow) {
            $output->writeln($json ? '{"ok":false,"error":"Invalid posting SLO state options."}' : '<error>Invalid posting SLO state options.</error>');

            return Command::INVALID;
        }

        try {
            $trendPolicy = new PostingSloTrendPolicy(
                new PostingSloPolicy(minimumSamples: $shortMinSamples),
                new PostingSloPolicy(minimumSamples: $longMinSamples),
                $shortBurn,
                $longBurn,
            );
            $short = $this->healthService->snapshot($shortWindow);
            $long = $this->healthService->snapshot($longWindow);
            $observed = $trendPolicy->assess($short, $long);
            $transition = $this->stateService->apply($scope, $observed, $breachEvaluations, $recoveryEvaluations);
        } catch (\Throwable $exception) {
            $error = trim($exception->getMessage()) ?: $exception::class;
            $output->writeln($json ? json_encode(['ok' => false, 'error' => $error], JSON_THROW_ON_ERROR) : '<error>'.$error.'</error>');

            return Command::INVALID;
        }

        $data = [
            'ok' => PostingHealthStatus::Healthy === $transition->currentStatus,
            'scope' => $transition->scope,
            'previous_status' => $transition->previousStatus->value,
            'current_status' => $transition->currentStatus->value,
            'observed_status' => $transition->observedStatus->value,
            'pending_status' => $transition->pendingStatus?->value,
            'pending_count' => $transition->pendingCount,
            'required_count' => $transition->requiredCount,
            'changed' => $transition->changed,
            'reasons' => $transition->reasons,
        ];

        if ($json) {
            $output->writeln(json_encode($data, JSON_THROW_ON_ERROR));
        } else {
            $io = new SymfonyStyle($input, $output);
            $io->definitionList(
                ['Scope' => $transition->scope],
                ['Observed' => $transition->observedStatus->value],
                ['Persisted' => $transition->currentStatus->value],
                ['Previous' => $transition->previousStatus->value],
                ['Pending' => null === $transition->pendingStatus ? 'none' : $transition->pendingStatus->value.' '.$transition->pendingCount.'/'.$transition->requiredCount],
                ['Changed' => $transition->changed ? 'yes' : 'no'],
                ['Reasons' => [] === $transition->reasons ? 'none' : implode(', ', $transition->reasons)],
            );
        }

        return match ($transition->currentStatus) {
            PostingHealthStatus::Healthy => Command::SUCCESS,
            PostingHealthStatus::Degraded => self::EXIT_DEGRADED,
            PostingHealthStatus::Critical => Command::FAILURE,
        };
    }
}
