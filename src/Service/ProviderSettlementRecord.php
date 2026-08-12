<?php

declare(strict_types=1);

namespace App\Walleting\Service;

final readonly class ProviderSettlementRecord
{
    public string $operation;
    public string $operationId;
    public int $amountMinor;
    public string $currency;
    public string $status;

    public function __construct(string $operation, string $operationId, int $amountMinor, string $currency, string $status)
    {
        $operation = trim($operation);
        $operationId = trim($operationId);
        $currency = strtoupper(trim($currency));
        $status = trim($status);
        if (!in_array($operation, ['funding', 'withdrawal'], true) || '' === $operationId || $amountMinor <= 0 || 1 !== preg_match('/^[A-Z]{3}$/', $currency) || '' === $status) {
            throw new \InvalidArgumentException('Provider settlement record is invalid.');
        }
        $this->operation = $operation;
        $this->operationId = $operationId;
        $this->amountMinor = $amountMinor;
        $this->currency = $currency;
        $this->status = $status;
    }

    public function externalReference(): string
    {
        return $this->operation.':'.$this->operationId;
    }
}
