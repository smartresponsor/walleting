<?php

declare(strict_types=1);

namespace App\Walleting\Command;

use App\Walleting\Service\PostingHealthService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'walleting:posting:metrics:cleanup', description: 'Delete retained posting metric samples in bounded batches.')]
final class PostingMetricsCleanupCommand extends Command
{
    public function __construct(private readonly PostingHealthService $healthService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('retention-days', null, InputOption::VALUE_REQUIRED, 'Retain metric samples for this many days (1-3650).', '30')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum rows to delete per run (1-5000).', '500')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Write machine-readable JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $retentionDays = filter_var($input->getOption('retention-days'), FILTER_VALIDATE_INT);
        $limit = filter_var($input->getOption('limit'), FILTER_VALIDATE_INT);
        $json = (bool) $input->getOption('json');
        if (!is_int($retentionDays) || $retentionDays < 1 || $retentionDays > 3650 || !is_int($limit) || $limit < 1 || $limit > 5000) {
            $output->writeln($json ? '{"ok":false,"error":"Invalid posting metric retention bounds."}' : '<error>Invalid posting metric retention bounds.</error>');

            return Command::INVALID;
        }

        try {
            $deleted = $this->healthService->cleanup($retentionDays, $limit);
        } catch (\Throwable $exception) {
            $error = trim($exception->getMessage()) ?: $exception::class;
            $output->writeln($json ? json_encode(['ok' => false, 'error' => $error], JSON_THROW_ON_ERROR) : '<error>'.$error.'</error>');

            return Command::FAILURE;
        }

        $data = ['ok' => true, 'deleted' => $deleted, 'retention_days' => $retentionDays, 'limit' => $limit];
        $output->writeln($json ? json_encode($data, JSON_THROW_ON_ERROR) : sprintf('Deleted %d posting metric samples.', $deleted));

        return Command::SUCCESS;
    }
}
