<?php

declare(strict_types=1);

namespace App\Walleting\Command;

use App\Walleting\Service\OutboxService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'walleting:outbox:recover-claims',
    description: 'Return stale claimed outbox messages to the retry queue.',
)]
final class RecoverOutboxClaimsCommand extends Command
{
    public function __construct(private readonly OutboxService $outboxService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Claim lease timeout in seconds (1-86400).', '300')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum stale claims to recover (1-500).', '100')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Write one machine-readable JSON result.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $timeout = filter_var($input->getOption('timeout'), FILTER_VALIDATE_INT);
        $limit = filter_var($input->getOption('limit'), FILTER_VALIDATE_INT);
        $json = (bool) $input->getOption('json');

        if (!is_int($timeout) || $timeout < 1 || $timeout > 86400) {
            return $this->writeError($output, $json, 'The --timeout option must be an integer between 1 and 86400.');
        }
        if (!is_int($limit) || $limit < 1 || $limit > 500) {
            return $this->writeError($output, $json, 'The --limit option must be an integer between 1 and 500.');
        }

        try {
            $recovered = $this->outboxService->recoverStaleClaims($timeout, $limit);
        } catch (\Throwable $exception) {
            return $this->writeError($output, $json, trim($exception->getMessage()) ?: $exception::class);
        }

        if ($json) {
            $output->writeln(json_encode(['ok' => true, 'recovered' => $recovered], JSON_THROW_ON_ERROR));
        } else {
            $io = new SymfonyStyle($input, $output);
            $io->success(sprintf('Recovered %d stale outbox claim(s).', $recovered));
        }

        return Command::SUCCESS;
    }

    private function writeError(OutputInterface $output, bool $json, string $error): int
    {
        $output->writeln($json
            ? json_encode(['ok' => false, 'error' => $error], JSON_THROW_ON_ERROR)
            : '<error>'.$error.'</error>');

        return Command::INVALID;
    }
}
