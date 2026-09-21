<?php

declare(strict_types=1);

namespace App\Walleting\Tests\Integration;

use App\Walleting\Service\WalletOutboxService;
use App\Walleting\Service\WalletPostingDbalExecutor;
use App\Walleting\Service\WalletPostingExecutorInterface;
use App\Walleting\Tests\Contract\PostingExecutorContractTest;

final class PostingDbalExecutorContractTest extends PostingExecutorContractTest
{
    protected function createExecutor(): WalletPostingExecutorInterface
    {
        $outboxService = new WalletOutboxService($this->entityManager, $this->connection);

        return new WalletPostingDbalExecutor($this->connection, $outboxService, new \App\Walleting\Policy\Posting\WalletPostingRetryPolicy(), new \App\Walleting\Service\WalletNullPostingTelemetry());
    }
}
