<?php

declare(strict_types=1);

namespace App\Walleting\Tests\Integration;

use App\Walleting\Service\OutboxService;
use App\Walleting\Service\PostingDbalExecutor;
use App\Walleting\Service\PostingExecutorInterface;
use App\Walleting\Tests\Contract\PostingExecutorContractTest;

final class PostingDbalExecutorContractTest extends PostingExecutorContractTest
{
    protected function createExecutor(): PostingExecutorInterface
    {
        $outboxService = new OutboxService($this->entityManager, $this->connection);

        return new PostingDbalExecutor($this->connection, $outboxService, new \App\Walleting\Service\PostingRetryPolicy(), new \App\Walleting\Service\NullPostingTelemetry());
    }
}
