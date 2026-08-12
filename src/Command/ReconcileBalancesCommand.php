<?php

declare(strict_types=1);

namespace App\Walleting\Command;

use App\Walleting\Service\BalanceReadService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'walleting:balance:reconcile', description: 'Compare account balance projections against immutable postings.')]
final class ReconcileBalancesCommand extends Command
{
    public function __construct(private readonly BalanceReadService $balanceReadService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum mismatches to return (1-500).', '100')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Write machine-readable JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = filter_var($input->getOption('limit'), FILTER_VALIDATE_INT);
        $json = (bool) $input->getOption('json');
        if (!is_int($limit) || $limit < 1 || $limit > 500) {
            $output->writeln($json ? '{"ok":false,"error":"Invalid limit."}' : '<error>Invalid limit.</error>');

            return Command::INVALID;
        }

        try {
            $mismatches = $this->balanceReadService->reconciliationMismatches($limit);
        } catch (\Throwable $exception) {
            $error = trim($exception->getMessage()) ?: $exception::class;
            $output->writeln($json ? json_encode(['ok' => false, 'error' => $error], JSON_THROW_ON_ERROR) : '<error>'.$error.'</error>');

            return Command::FAILURE;
        }

        $ok = [] === $mismatches;
        if ($json) {
            $output->writeln(json_encode(['ok' => $ok, 'mismatch_count' => count($mismatches), 'mismatches' => $mismatches], JSON_THROW_ON_ERROR));
        } else {
            $io = new SymfonyStyle($input, $output);
            if ($ok) {
                $io->success('Account balance projection matches immutable postings.');
            } else {
                $io->error(sprintf('Found %d account balance projection mismatch(es).', count($mismatches)));
                $io->table(array_keys($mismatches[0]), array_map('array_values', $mismatches));
            }
        }

        return $ok ? Command::SUCCESS : Command::FAILURE;
    }
}
