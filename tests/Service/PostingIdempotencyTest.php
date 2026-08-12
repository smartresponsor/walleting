<?php

declare(strict_types=1);

namespace App\Walleting\Tests\Service;

use App\Walleting\Entity\Account;
use App\Walleting\Entity\LedgerTransaction;
use App\Walleting\Entity\Wallet;
use App\Walleting\Enum\AccountCategory;
use App\Walleting\Enum\TransactionType;
use App\Walleting\Ledger\PostingInstruction;
use App\Walleting\Service\OutboxService;
use App\Walleting\Service\PostingDbalExecutor;
use App\Walleting\Service\PostingService;
use Doctrine\DBAL\Connection;
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
        $connection = $this->createStub(Connection::class);
        $outboxService = new OutboxService($entityManager, $connection);
        $service = new PostingService(
            $entityManager,
            $outboxService,
            new PostingDbalExecutor($connection, $outboxService, new \App\Walleting\Service\PostingRetryPolicy(), new \App\Walleting\Service\NullPostingTelemetry()),
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Idempotency key is already bound to a different financial request.');

        $service->credit('credit-1', [
            new PostingInstruction($cash, 1000),
            new PostingInstruction($clearing, -1000),
        ]);
    }
}
