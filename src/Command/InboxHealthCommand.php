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

#[AsCommand(name: 'walleting:inbox:health', description: 'Inspect inbox processing health and stuck receipts.')]
final class InboxHealthCommand extends Command
{
    public function __construct(private readonly InboxService $inboxService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('stuck-after', null, InputOption::VALUE_REQUIRED, 'Processing age considered stuck in seconds (1-86400).', '300')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum stuck receipts to include (1-500).', '20')
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Optional source for one receipt identity diagnostic.')
            ->addOption('message-id', null, InputOption::VALUE_REQUIRED, 'Optional message id for one receipt identity diagnostic.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Write machine-readable JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $stuckAfter = filter_var($input->getOption('stuck-after'), FILTER_VALIDATE_INT);
        $limit = filter_var($input->getOption('limit'), FILTER_VALIDATE_INT);
        $json = (bool) $input->getOption('json');
        $source = trim((string) ($input->getOption('source') ?? ''));
        $messageId = trim((string) ($input->getOption('message-id') ?? ''));
        if (('' === $source) !== ('' === $messageId)) {
            $output->writeln($json ? '{"ok":false,"error":"source and message-id must be provided together."}' : '<error>source and message-id must be provided together.</error>');

            return Command::INVALID;
        }
        if (!is_int($stuckAfter) || $stuckAfter < 1 || $stuckAfter > 86400 || !is_int($limit) || $limit < 1 || $limit > 500) {
            $output->writeln($json ? '{"ok":false,"error":"Invalid stuck-after or limit."}' : '<error>Invalid stuck-after or limit.</error>');

            return Command::INVALID;
        }

        try {
            $snapshot = $this->inboxService->healthSnapshot($stuckAfter);
            $stuck = $this->inboxService->stuckProcessing($stuckAfter, $limit);
            $receipt = '' === $source ? [] : $this->inboxService->receiptDiagnostics($source, $messageId);
        } catch (\Throwable $exception) {
            $error = trim($exception->getMessage()) ?: $exception::class;
            $output->writeln($json ? json_encode(['ok' => false, 'error' => $error], JSON_THROW_ON_ERROR) : '<error>'.$error.'</error>');

            return Command::INVALID;
        }

        $data = [
            'ok' => $snapshot->isHealthy(),
            'status_counts' => $snapshot->statusCounts,
            'oldest_processing_age_seconds' => $snapshot->oldestProcessingAgeSeconds,
            'stuck_processing_count' => $snapshot->stuckProcessingCount,
            'stuck_receipts' => $stuck,
            'receipt_diagnostic' => $receipt,
        ];
        if ($json) {
            $output->writeln(json_encode($data, JSON_THROW_ON_ERROR));
        } else {
            $io = new SymfonyStyle($input, $output);
            $io->definitionList(
                ['Status counts' => json_encode($snapshot->statusCounts, JSON_THROW_ON_ERROR)],
                ['Oldest processing age' => null === $snapshot->oldestProcessingAgeSeconds ? 'none' : $snapshot->oldestProcessingAgeSeconds.' seconds'],
                ['Stuck processing' => (string) $snapshot->stuckProcessingCount],
            );
            $snapshot->isHealthy() ? $io->success('Inbox is healthy.') : $io->error('Inbox has stuck processing receipts.');
            if ([] !== $stuck) {
                $io->table(array_keys($stuck[0]), array_map('array_values', $stuck));
            }
        }

        return $snapshot->isHealthy() ? Command::SUCCESS : Command::FAILURE;
    }
}
