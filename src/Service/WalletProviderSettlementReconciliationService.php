<?php

declare(strict_types=1);

namespace App\Walleting\Service;

use App\Walleting\Entity\WalletFunding;
use App\Walleting\Entity\WalletReconciliationRun;
use App\Walleting\Entity\WalletWithdrawal;

final readonly class WalletProviderSettlementReconciliationService
{
    public function __construct(private WalletReconciliationService $reconciliationService)
    {
    }

    /**
     * @param list<WalletProviderSettlementRecord> $providerRecords
     * @param list<WalletFunding|WalletWithdrawal> $localOperations
     */
    public function execute(WalletReconciliationRun $run, array $providerRecords, array $localOperations): WalletReconciliationRun
    {
        $provider = [];
        foreach ($providerRecords as $record) {
            if (!$record instanceof WalletProviderSettlementRecord) {
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
            if ($operation instanceof WalletFunding) {
                $this->assertProvider($run, $operation->paymentInstrument()->provider());
                $local[] = [
                    'external_reference' => $this->providerOperationReference($operation),
                    'amount_minor' => $operation->amountMinor(),
                    'currency' => $operation->currency(),
                    'status' => $operation->status()->value,
                ];
                continue;
            }
            if ($operation instanceof WalletWithdrawal) {
                $this->assertProvider($run, $operation->paymentInstrument()->provider());
                $local[] = [
                    'external_reference' => $this->providerOperationReference($operation),
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

    private function providerOperationReference(WalletFunding|WalletWithdrawal $operation): string
    {
        $reference = $operation->providerOperationReference();
        if (null === $reference) {
            throw new \DomainException('Provider settlement reconciliation requires a bound provider operation reference.');
        }

        return ($operation instanceof WalletFunding ? 'funding:' : 'withdrawal:').$reference;
    }

    private function assertProvider(WalletReconciliationRun $run, string $provider): void
    {
        if ($run->provider() !== $provider) {
            throw new \DomainException('Local settlement operation provider does not match the reconciliation run provider.');
        }
    }
}
