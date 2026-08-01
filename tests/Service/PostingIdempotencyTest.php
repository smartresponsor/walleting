<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Account;
use App\Entity\LedgerTransaction;
use App\Entity\Wallet;
use App\Enum\AccountCategory;
use App\Enum\TransactionType;
use App\Ledger\PostingInstruction;
use App\Service\PostingService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

final class PostingIdempotencyTest extends TestCase
{
    public function testKeyCannotBeReusedForDifferentFinancialRequest(): void
    {
        $wallet = new Wallet('vendor', 'vendor-1');
        $cash = new Account($wallet, 'cash', 'USD', AccountCategory::Asset);
        $clearing = new Account($wallet, 'clearing', 'USD', AccountCategory::Clearing);
        $existing = new LedgerTransaction(
            TransactionType::Credit,
            'credit-1',
            requestHash: str_repeat('a', 64),
        );

        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findOneBy')->willReturn($existing);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);
        $service = new PostingService($entityManager);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Idempotency key is already bound to a different financial request.');

        $service->credit('credit-1', [
            new PostingInstruction($cash, 1000),
            new PostingInstruction($clearing, -1000),
        ]);
    }
}
