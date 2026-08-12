<?php

declare(strict_types=1);

namespace App\Walleting\Command;

use App\Walleting\Service\OutboxDeadLetterService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'walleting:outbox:requeue', description: 'Requeue one dead outbox message with an immutable operator audit record.')]
final class OutboxRequeueCommand extends Command
{
    public function __construct(private readonly OutboxDeadLetterService $deadLetterService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Dead outbox message UUID.')
            ->addOption('operator', null, InputOption::VALUE_REQUIRED, 'Operator identity performing the requeue.')
            ->addOption('reason', null, InputOption::VALUE_REQUIRED, 'Operational reason for requeue.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Write machine-readable JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = trim((string) $input->getOption('id'));
        $operator = trim((string) $input->getOption('operator'));
        $reason = trim((string) $input->getOption('reason'));
        $json = (bool) $input->getOption('json');
        if ('' === $id || '' === $operator || '' === $reason) {
            $output->writeln($json ? '{"ok":false,"error":"id, operator and reason are required."}' : '<error>id, operator and reason are required.</error>');

            return Command::INVALID;
        }

        try {
            $message = $this->deadLetterService->requeue($id, $operator, $reason);
        } catch (\Throwable $exception) {
            $error = trim($exception->getMessage()) ?: $exception::class;
            $output->writeln($json ? json_encode(['ok' => false, 'error' => $error], JSON_THROW_ON_ERROR) : '<error>'.$error.'</error>');

            return Command::FAILURE;
        }

        $data = [
            'ok' => true,
            'id' => $message->id()->toRfc4122(),
            'status' => $message->status()->value,
            'attempt_count' => $message->attemptCount(),
            'available_at' => $message->availableAt()->format(DATE_ATOM),
        ];
        $output->writeln($json ? json_encode($data, JSON_THROW_ON_ERROR) : sprintf('Requeued outbox message %s with attempt count %d preserved.', $data['id'], $data['attempt_count']));

        return Command::SUCCESS;
    }
}
