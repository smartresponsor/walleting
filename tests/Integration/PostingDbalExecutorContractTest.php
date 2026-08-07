<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Service\OutboxService;
use App\Service\PostingDbalExecutor;
use App\Service\PostingExecutorInterface;
use App\Tests\Contract\PostingExecutorContractTest;

final class PostingDbalExecutorContractTest extends PostingExecutorContractTest
{
    protected function createExecutor(): PostingExecutorInterface
    {
        $outboxService = new OutboxService($this->entityManager, $this->connection);

        return new PostingDbalExecutor($this->connection, $outboxService);
    }
}
