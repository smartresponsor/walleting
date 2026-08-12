<?php

declare(strict_types=1);

namespace App\Walleting\Service;

use App\Walleting\Entity\Funding;
use App\Walleting\Entity\ReconciliationRun;
use App\Walleting\Entity\Withdrawal;

final readonly class ProviderSettlementReconciliationService
{
    public function __construct(private ReconciliationService $reconciliationService)
    {
    }

    /**
     * @param list<ProviderSettlementRecord> $providerRecords
     * @param list<Funding|Withdrawal> $localOperations
     */
    public function execute(ReconciliationRun $run, array $providerRecords, array $localOperations): ReconciliationRun
    {
        $provider = [];
        foreach ($providerRecords as $record) {
            if (!$record instanceof ProviderSettlementRecord) {
                throw new \InvalidArgumentException('Every provider settlement item must be a ProviderSettlementRecord.');
            }
            $provider[] = [
                'external_reference' => $record->externalReference(),
                'amount_minor' => $record->amountMinor,
                'currency' => strtoupper(trim($record->currency)),
                'status' => trim($record->status),
            ];
        }

        $local = [];
        foreach ($localOperations as $operation) {
            if ($operation instanceof Funding) {
                $this->assertProvider($run, $operation->paymentInstrument()->provider());
                $local[] = [
                    'external_reference' => 'funding:'.$operation->id()->toRfc4122(),
                    'amount_minor' => $operation->amountMinor(),
                    'currency' => $operation->currency(),
                    'status' => $operation->status()->value,
                ];
                continue;
            }
            if ($operation instanceof Withdrawal) {
                $this->assertProvider($run, $operation->paymentInstrument()->provider());
                $local[] = [
                    'external_reference' => 'withdrawal:'.$operation->id()->toRfc4122(),
                    'amount_minor' => $operation->amountMinor(),
                    'currency' => $operation->currency(),
                    'status' => $operation->status()->value,
                ];
                continue;
            }

            throw new \InvalidArgumentException('Local settlement operation must be Funding or Withdrawal.');
        }

        return $this->reconciliationService->execute($run, $provider, $local);
    }

    private function assertProvider(ReconciliationRun $run, string $provider): void
    {
        if ($run->provider() !== $provider) {
            throw new \DomainException('Local settlement operation provider does not match the reconciliation run provider.');
        }
    }
}
