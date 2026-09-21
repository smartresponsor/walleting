<?php

declare(strict_types=1);

namespace App\Walleting\Service;

use App\Walleting\Entity\WalletReconciliationMismatch;
use App\Walleting\Entity\WalletReconciliationRun;
use App\Walleting\Enum\WalletReconciliationMismatchType;
use Doctrine\ORM\EntityManagerInterface;

final readonly class WalletReconciliationService
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @param list<array{external_reference:string, amount_minor:int, currency:string, status:string}> $providerRecords
     * @param list<array{external_reference:string, amount_minor:int, currency:string, status:string}> $localRecords
     */
    public function execute(WalletReconciliationRun $run, array $providerRecords, array $localRecords): WalletReconciliationRun
    {
        return $this->entityManager->wrapInTransaction(function () use ($run, $providerRecords, $localRecords): WalletReconciliationRun {
            $run->start();
            $provider = $this->index($providerRecords);
            $local = $this->index($localRecords);

            foreach (array_unique([...array_keys($provider), ...array_keys($local)]) as $reference) {
                $providerRecord = $provider[$reference] ?? null;
                $localRecord = $local[$reference] ?? null;

                if (null === $localRecord) {
                    $this->mismatch($run, WalletReconciliationMismatchType::MissingLocal, $reference, ['provider' => $providerRecord]);
                    continue;
                }
                if (null === $providerRecord) {
                    $this->mismatch($run, WalletReconciliationMismatchType::MissingProvider, $reference, ['local' => $localRecord]);
                    continue;
                }
                if ($providerRecord['amount_minor'] !== $localRecord['amount_minor']) {
                    $this->mismatch($run, WalletReconciliationMismatchType::Amount, $reference, ['provider' => $providerRecord['amount_minor'], 'local' => $localRecord['amount_minor']]);
                    continue;
                }
                if ($providerRecord['currency'] !== $localRecord['currency']) {
                    $this->mismatch($run, WalletReconciliationMismatchType::Currency, $reference, ['provider' => $providerRecord['currency'], 'local' => $localRecord['currency']]);
                    continue;
                }
                if ($providerRecord['status'] !== $localRecord['status']) {
                    $this->mismatch($run, WalletReconciliationMismatchType::Status, $reference, ['provider' => $providerRecord['status'], 'local' => $localRecord['status']]);
                    continue;
                }

                $run->recordMatch();
            }

            $run->complete();
            $this->entityManager->flush();

            return $run;
        });
    }

    /** @param list<array{external_reference:string, amount_minor:int, currency:string, status:string}> $records */
    private function index(array $records): array
    {
        $indexed = [];
        foreach ($records as $record) {
            $reference = trim($record['external_reference'] ?? '');
            $currency = strtoupper(trim($record['currency'] ?? ''));
            $status = trim($record['status'] ?? '');
            $amount = $record['amount_minor'] ?? null;
            if ('' === $reference || !is_int($amount) || 1 !== preg_match('/^[A-Z]{3}$/', $currency) || '' === $status) {
                throw new \InvalidArgumentException('Every reconciliation record requires reference, integer amount, currency, and status.');
            }
            if (isset($indexed[$reference])) {
                throw new \DomainException('Duplicate reconciliation external reference: '.$reference);
            }
            $indexed[$reference] = [
                'external_reference' => $reference,
                'amount_minor' => $amount,
                'currency' => $currency,
                'status' => $status,
            ];
        }

        ksort($indexed);

        return $indexed;
    }

    private function mismatch(WalletReconciliationRun $run, WalletReconciliationMismatchType $type, string $reference, array $details): void
    {
        $existing = $this->entityManager->getRepository(WalletReconciliationMismatch::class)->findOneBy([
            'run' => $run,
            'externalReference' => $reference,
            'type' => $type,
        ]);
        if (!$existing instanceof WalletReconciliationMismatch) {
            $this->entityManager->persist(new WalletReconciliationMismatch($run, $type, $reference, $details));
        }
        $run->recordMismatch();
    }
}
