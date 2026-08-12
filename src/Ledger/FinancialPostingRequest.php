<?php

declare(strict_types=1);

namespace App\Walleting\Ledger;

use App\Walleting\Enum\TransactionType;

final readonly class FinancialPostingRequest
{
    /** @param non-empty-list<PostingInstruction> $instructions */
    public function __construct(
        public TransactionType $type,
        public array $instructions,
        public array $metadata = [],
    ) {
    }

    public function hash(): string
    {
        return hash('sha256', json_encode($this->canonicalPayload(), JSON_THROW_ON_ERROR));
    }

    /** @return array{type:string,postings:list<array{account_id:string,amount_minor:int,currency:string}>,metadata:array<mixed>} */
    public function canonicalPayload(): array
    {
        return [
            'type' => $this->type->value,
            'postings' => array_map(static fn (PostingInstruction $instruction): array => [
                'account_id' => $instruction->account->id()->toRfc4122(),
                'amount_minor' => $instruction->amountMinor,
                'currency' => $instruction->account->currency(),
            ], $this->instructions),
            'metadata' => $this->normalize($this->metadata),
        ];
    }

    private function normalize(array $value): array
    {
        ksort($value);
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = $this->normalize($item);
            }
        }
        unset($item);

        return $value;
    }
}
