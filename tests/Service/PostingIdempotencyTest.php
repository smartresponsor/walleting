<?php

declare(strict_types=1);

namespace App\Walleting\Tests\Service;

use App\Walleting\Entity\Wallet;
use App\Walleting\Entity\WalletAccount;
use App\Walleting\Entity\WalletLedgerTransaction;
use App\Walleting\Enum\WalletAccountCategory;
use App\Walleting\Enum\WalletTransactionType;
use App\Walleting\Service\WalletOutboxService;
use App\Walleting\Service\WalletPostingDbalExecutor;
use App\Walleting\Service\WalletPostingService;
use App\Walleting\ValueObject\Ledger\WalletPostingInstruction;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

final class PostingIdempotencyTest extends TestCase
{
    public function testKeyCannotBeReusedForDifferentFinancialRequest(): void
    {
        $wallet = new Wallet('vendor', 'vendor-1');
        $cash = new WalletAccount($wallet, 'cash', 'USD', WalletAccountCategory::Asset);
        $clearing = new WalletAccount($wallet, 'clearing', 'USD', WalletAccountCategory::Clearing);
        $existing = new WalletLedgerTransaction(
            WalletTransactionType::Credit,
            'credit-1',
            requestHash: str_repeat('a', 64),
        );

        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findOneBy')->willReturn($existing);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);
        $connection = $this->createStub(Connection::class);
        $outboxService = new WalletOutboxService($entityManager, $connection);
        $service = new WalletPostingService(
            $entityManager,
            $outboxService,
            new WalletPostingDbalExecutor($connection, $outboxService, new \App\Walleting\Policy\Posting\WalletPostingRetryPolicy(), new \App\Walleting\Service\WalletNullPostingTelemetry()),
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Idempotency key is already bound to a different financial request.');

        $service->credit('credit-1', [
            new WalletPostingInstruction($cash, 1000),
            new WalletPostingInstruction($clearing, -1000),
        ]);
    }
}
