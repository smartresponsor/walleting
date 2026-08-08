<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\OutboxService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'walleting:outbox:health', description: 'Inspect transactional outbox health and dead letters.')]
final class OutboxHealthCommand extends Command
{
    public function __construct(private readonly OutboxService $outboxService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('max-age', null, InputOption::VALUE_REQUIRED, 'Maximum healthy dispatchable age in seconds.', '300')
            ->addOption('dead-limit', null, InputOption::VALUE_REQUIRED, 'Dead letters to include (1-500).', '20')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Write machine-readable JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $maxAge = filter_var($input->getOption('max-age'), FILTER_VALIDATE_INT);
        $deadLimit = filter_var($input->getOption('dead-limit'), FILTER_VALIDATE_INT);
        $json = (bool) $input->getOption('json');
        if (!is_int($maxAge) || $maxAge < 1 || !is_int($deadLimit) || $deadLimit < 1 || $deadLimit > 500) {
            $output->writeln($json ? '{"ok":false,"error":"Invalid max-age or dead-limit."}' : '<error>Invalid max-age or dead-limit.</error>');

            return Command::INVALID;
        }

        try {
            $snapshot = $this->outboxService->healthSnapshot();
            $deadLetters = $this->outboxService->deadLetters($deadLimit);
        } catch (\Throwable $exception) {
            $error = trim($exception->getMessage()) ?: $exception::class;
            $output->writeln($json ? json_encode(['ok' => false, 'error' => $error], JSON_THROW_ON_ERROR) : '<error>'.$error.'</error>');

            return Command::INVALID;
        }

        $healthy = $snapshot->isHealthy($maxAge);
        $data = [
            'ok' => $healthy,
            'status_counts' => $snapshot->statusCounts,
            'oldest_dispatchable_age_seconds' => $snapshot->oldestDispatchableAgeSeconds,
            'dead_count' => $snapshot->deadCount,
            'dead_letters' => $deadLetters,
        ];
        if ($json) {
            $output->writeln(json_encode($data, JSON_THROW_ON_ERROR));
        } else {
            $io = new SymfonyStyle($input, $output);
            $io->definitionList(
                ['Status counts' => json_encode($snapshot->statusCounts, JSON_THROW_ON_ERROR)],
                ['Oldest dispatchable age' => null === $snapshot->oldestDispatchableAgeSeconds ? 'none' : $snapshot->oldestDispatchableAgeSeconds.' seconds'],
                ['Dead letters' => (string) $snapshot->deadCount],
            );
            $healthy ? $io->success('Outbox is healthy.') : $io->error('Outbox health thresholds are exceeded.');
            if ([] !== $deadLetters) {
                $io->table(array_keys($deadLetters[0]), array_map('array_values', $deadLetters));
                $io->section('Operator next actions');
                foreach ($deadLetters as $deadLetter) {
                    $io->writeln(sprintf('Inspect %s: walleting:outbox:inspect --id=%s', $deadLetter['id'], $deadLetter['id']));
                }
            }
        }

        return $healthy ? Command::SUCCESS : Command::FAILURE;
    }
}
