<?php

declare(strict_types=1);

namespace App\Walleting\Tests\Ledger;

use App\Walleting\Entity\Wallet;
use App\Walleting\Entity\WalletAccount;
use App\Walleting\Enum\WalletAccountCategory;
use App\Walleting\Enum\WalletTransactionType;
use App\Walleting\ValueObject\Ledger\WalletFinancialPostingRequest;
use App\Walleting\ValueObject\Ledger\WalletPostingInstruction;
use PHPUnit\Framework\TestCase;

final class FinancialPostingRequestTest extends TestCase
{
    public function testMetadataKeyOrderDoesNotChangeCanonicalHash(): void
    {
        [$cash, $clearing] = $this->accounts();
        $instructions = [
            new WalletPostingInstruction($cash, 1000),
            new WalletPostingInstruction($clearing, -1000),
        ];

        $first = new WalletFinancialPostingRequest(WalletTransactionType::Credit, $instructions, [
            'operation' => 'funding',
            'context' => ['b' => 2, 'a' => 1],
        ]);
        $second = new WalletFinancialPostingRequest(WalletTransactionType::Credit, $instructions, [
            'context' => ['a' => 1, 'b' => 2],
            'operation' => 'funding',
        ]);

        self::assertSame($first->hash(), $second->hash());
        self::assertSame($first->canonicalPayload(), $second->canonicalPayload());
    }

    public function testPostingSequenceChangesCanonicalHash(): void
    {
        [$cash, $clearing] = $this->accounts();
        $first = new WalletFinancialPostingRequest(WalletTransactionType::Transfer, [
            new WalletPostingInstruction($cash, -500),
            new WalletPostingInstruction($clearing, 500),
        ]);
        $second = new WalletFinancialPostingRequest(WalletTransactionType::Transfer, [
            new WalletPostingInstruction($clearing, 500),
            new WalletPostingInstruction($cash, -500),
        ]);

        self::assertNotSame($first->hash(), $second->hash());
    }

    public function testTransactionTypeChangesCanonicalHash(): void
    {
        [$cash, $clearing] = $this->accounts();
        $instructions = [
            new WalletPostingInstruction($cash, 100),
            new WalletPostingInstruction($clearing, -100),
        ];

        self::assertNotSame(
            (new WalletFinancialPostingRequest(WalletTransactionType::Credit, $instructions))->hash(),
            (new WalletFinancialPostingRequest(WalletTransactionType::Transfer, $instructions))->hash(),
        );
    }

    /** @return array{Account, Account} */
    private function accounts(): array
    {
        $wallet = new Wallet('vendor', 'financial-request-vendor');

        return [
            new WalletAccount($wallet, 'cash', 'USD', WalletAccountCategory::Asset),
            new WalletAccount($wallet, 'clearing', 'USD', WalletAccountCategory::Clearing),
        ];
    }
}
