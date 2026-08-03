<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\OutboxDispatcher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'walleting:outbox:dispatch',
    description: 'Claim and dispatch one bounded batch of transactional outbox messages.',
)]
final class DispatchOutboxCommand extends Command
{
    public function __construct(private readonly OutboxDispatcher $dispatcher)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum messages to claim (1-500).', '100')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Write one machine-readable JSON result.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = filter_var($input->getOption('limit'), FILTER_VALIDATE_INT);
        if (!is_int($limit) || $limit < 1 || $limit > 500) {
            return $this->writeError($output, (bool) $input->getOption('json'), 'The --limit option must be an integer between 1 and 500.');
        }

        $startedAt = microtime(true);
        try {
            $report = $this->dispatcher->dispatchBatchReport($limit);
        } catch (\Throwable $exception) {
            return $this->writeError($output, (bool) $input->getOption('json'), trim($exception->getMessage()) ?: $exception::class);
        }

        $metrics = [
            'claimed' => $report->claimed,
            'dispatched' => $report->dispatched,
            'retry_scheduled' => $report->retryScheduled,
            'dead' => $report->dead,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ];

        if ((bool) $input->getOption('json')) {
            $output->writeln(json_encode(['ok' => !$report->hasFailures(), 'metrics' => $metrics], JSON_THROW_ON_ERROR));
        } else {
            $io = new SymfonyStyle($input, $output);
            $io->definitionList(
                ['Claimed' => (string) $metrics['claimed']],
                ['Dispatched' => (string) $metrics['dispatched']],
                ['Retry scheduled' => (string) $metrics['retry_scheduled']],
                ['Dead' => (string) $metrics['dead']],
                ['Duration' => $metrics['duration_ms'].' ms'],
            );
            $report->hasFailures()
                ? $io->warning('Outbox batch completed with delivery failures.')
                : $io->success('Outbox batch completed.');
        }

        return $report->hasFailures() ? Command::FAILURE : Command::SUCCESS;
    }

    private function writeError(OutputInterface $output, bool $json, string $error): int
    {
        if ($json) {
            $output->writeln(json_encode(['ok' => false, 'error' => $error], JSON_THROW_ON_ERROR));
        } else {
            $output->writeln('<error>'.$error.'</error>');
        }

        return Command::INVALID;
    }
}
