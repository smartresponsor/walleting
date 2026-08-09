<?php

declare(strict_types=1);

namespace App\Service;

final readonly class ProviderOperationRequest
{
    public function __construct(
        public string $operation,
        public string $operationId,
        public string $idempotencyKey,
        public string $provider,
        public string $providerReference,
        public int $amountMinor,
        public string $currency,
    ) {
        if (!in_array($operation, ['funding', 'withdrawal'], true) || '' === trim($operationId) || '' === trim($idempotencyKey) || '' === trim($provider) || '' === trim($providerReference) || $amountMinor <= 0 || 1 !== preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new \InvalidArgumentException('Provider operation request is invalid.');
        }
    }
}
