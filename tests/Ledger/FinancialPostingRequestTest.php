<?php

declare(strict_types=1);

namespace App\Tests\Ledger;

use App\Entity\Account;
use App\Entity\Wallet;
use App\Enum\AccountCategory;
use App\Enum\TransactionType;
use App\Ledger\FinancialPostingRequest;
use App\Ledger\PostingInstruction;
use PHPUnit\Framework\TestCase;

final class FinancialPostingRequestTest extends TestCase
{
    public function testMetadataKeyOrderDoesNotChangeCanonicalHash(): void
    {
        [$cash, $clearing] = $this->accounts();
        $instructions = [
            new PostingInstruction($cash, 1000),
            new PostingInstruction($clearing, -1000),
        ];

        $first = new FinancialPostingRequest(TransactionType::Credit, $instructions, [
            'operation' => 'funding',
            'context' => ['b' => 2, 'a' => 1],
        ]);
        $second = new FinancialPostingRequest(TransactionType::Credit, $instructions, [
            'context' => ['a' => 1, 'b' => 2],
            'operation' => 'funding',
        ]);

        self::assertSame($first->hash(), $second->hash());
        self::assertSame($first->canonicalPayload(), $second->canonicalPayload());
    }

    public function testPostingSequenceChangesCanonicalHash(): void
    {
        [$cash, $clearing] = $this->accounts();
        $first = new FinancialPostingRequest(TransactionType::Transfer, [
            new PostingInstruction($cash, -500),
            new PostingInstruction($clearing, 500),
        ]);
        $second = new FinancialPostingRequest(TransactionType::Transfer, [
            new PostingInstruction($clearing, 500),
            new PostingInstruction($cash, -500),
        ]);

        self::assertNotSame($first->hash(), $second->hash());
    }

    public function testTransactionTypeChangesCanonicalHash(): void
    {
        [$cash, $clearing] = $this->accounts();
        $instructions = [
            new PostingInstruction($cash, 100),
            new PostingInstruction($clearing, -100),
        ];

        self::assertNotSame(
            (new FinancialPostingRequest(TransactionType::Credit, $instructions))->hash(),
            (new FinancialPostingRequest(TransactionType::Transfer, $instructions))->hash(),
        );
    }

    /** @return array{Account, Account} */
    private function accounts(): array
    {
        $wallet = new Wallet('vendor', 'financial-request-vendor');

        return [
            new Account($wallet, 'cash', 'USD', AccountCategory::Asset),
            new Account($wallet, 'clearing', 'USD', AccountCategory::Clearing),
        ];
    }
}
