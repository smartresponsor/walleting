<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\InboxService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'walleting:inbox:cleanup', description: 'Delete processed inbox receipts older than the retention threshold.')]
final class CleanupInboxReceiptsCommand extends Command
{
    public function __construct(private readonly InboxService $inboxService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('retention-days', null, InputOption::VALUE_REQUIRED, 'Processed receipt retention in days (1-3650).', '90')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum receipts to delete (1-500).', '100')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Write machine-readable JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $retentionDays = filter_var($input->getOption('retention-days'), FILTER_VALIDATE_INT);
        $limit = filter_var($input->getOption('limit'), FILTER_VALIDATE_INT);
        $json = (bool) $input->getOption('json');

        if (!is_int($retentionDays) || $retentionDays < 1 || $retentionDays > 3650 || !is_int($limit) || $limit < 1 || $limit > 500) {
            $output->writeln($json ? '{"ok":false,"error":"Invalid retention-days or limit."}' : '<error>Invalid retention-days or limit.</error>');

            return Command::INVALID;
        }

        try {
            $deleted = $this->inboxService->cleanupProcessed($retentionDays, $limit);
        } catch (\Throwable $exception) {
            $error = trim($exception->getMessage()) ?: $exception::class;
            $output->writeln($json ? json_encode(['ok' => false, 'error' => $error], JSON_THROW_ON_ERROR) : '<error>'.$error.'</error>');

            return Command::INVALID;
        }

        if ($json) {
            $output->writeln(json_encode(['ok' => true, 'deleted' => $deleted], JSON_THROW_ON_ERROR));
        } else {
            $io = new SymfonyStyle($input, $output);
            $io->success(sprintf('Deleted %d processed inbox receipt(s).', $deleted));
        }

        return Command::SUCCESS;
    }
}
