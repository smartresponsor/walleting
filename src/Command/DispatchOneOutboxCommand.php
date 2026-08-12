<?php

declare(strict_types=1);

namespace App\Walleting\Command;

use App\Walleting\Service\OutboxDispatcher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'walleting:outbox:dispatch-one', description: 'Dispatch exactly one selected outbox message by UUID.')]
final class DispatchOneOutboxCommand extends Command
{
    public function __construct(private readonly OutboxDispatcher $dispatcher)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Dispatchable outbox message UUID.')
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
            $report = $this->dispatcher->dispatchOneById($id);
        } catch (\Throwable $exception) {
            $error = trim($exception->getMessage()) ?: $exception::class;
            $output->writeln($json ? json_encode(['ok' => false, 'error' => $error], JSON_THROW_ON_ERROR) : '<error>'.$error.'</error>');

            return Command::FAILURE;
        }

        $data = [
            'ok' => !$report->hasFailures(),
            'id' => $id,
            'dispatched' => $report->dispatched,
            'retry_scheduled' => $report->retryScheduled,
            'dead' => $report->dead,
        ];
        if ($json) {
            $output->writeln(json_encode($data, JSON_THROW_ON_ERROR));
        } else {
            $io = new SymfonyStyle($input, $output);
            $io->definitionList(
                ['Message' => $id],
                ['Dispatched' => (string) $report->dispatched],
                ['Retry scheduled' => (string) $report->retryScheduled],
                ['Dead' => (string) $report->dead],
            );
            $report->hasFailures() ? $io->warning('Selected outbox message was not delivered successfully.') : $io->success('Selected outbox message was dispatched.');
        }

        return $report->hasFailures() ? Command::FAILURE : Command::SUCCESS;
    }
}
