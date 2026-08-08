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

#[AsCommand(name: 'walleting:outbox:inspect', description: 'Inspect one outbox message and its immutable requeue history.')]
final class OutboxInspectCommand extends Command
{
    public function __construct(private readonly OutboxService $outboxService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Outbox message UUID.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Write machine-readable JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = trim((string) $input->getOption('id'));
        $json = (bool) $input->getOption('json');
        if ('' === $id) {
            $output->writeln($json ? '{"ok":false,"error":"id is required."}' : '<error>id is required.</error>');

            return Command::INVALID;
        }

        try {
            $detail = $this->outboxService->inspect($id);
        } catch (\Throwable $exception) {
            $error = trim($exception->getMessage()) ?: $exception::class;
            $output->writeln($json ? json_encode(['ok' => false, 'error' => $error], JSON_THROW_ON_ERROR) : '<error>'.$error.'</error>');

            return Command::FAILURE;
        }

        if ($json) {
            $output->writeln(json_encode(['ok' => true, 'message' => $detail], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        $io = new SymfonyStyle($input, $output);
        $history = $detail['requeue_history'];
        unset($detail['requeue_history']);
        $payload = $detail['payload'];
        unset($detail['payload']);
        $io->definitionList(...array_map(
            static fn (string $key, mixed $value): array => [$key => null === $value ? 'null' : (string) $value],
            array_keys($detail),
            array_values($detail),
        ));
        $io->section('Payload');
        $io->writeln(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $io->section('Requeue history');
        if ([] === $history) {
            $io->writeln('none');
        } else {
            $io->table(array_keys($history[0]), array_map('array_values', $history));
        }

        return Command::SUCCESS;
    }
}
