<?php

declare(strict_types=1);

namespace App\Walleting\Service;

final readonly class MoneyOperationView
{
    public function __construct(
        public string $id,
        public string $type,
        public string $status,
        public int $amountMinor,
        public string $currency,
        public string $provider,
        public string $providerReference,
        public ?string $providerOperationReference,
        public ?string $transactionId,
        public ?string $reversalTransactionId,
    ) {
        if ('' === trim($id) || !in_array($type, ['funding', 'withdrawal'], true) || '' === trim($status) || $amountMinor <= 0 || 1 !== preg_match('/^[A-Z]{3}$/', $currency) || '' === trim($provider) || '' === trim($providerReference) || (null !== $providerOperationReference && '' === trim($providerOperationReference))) {
            throw new \InvalidArgumentException('Money operation view is invalid.');
        }
    }
}
