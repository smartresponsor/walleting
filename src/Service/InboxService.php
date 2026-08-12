<?php

declare(strict_types=1);

namespace App\Walleting\Service;

use App\Walleting\Entity\InboxReceipt;
use App\Walleting\Inbox\InboxHealthSnapshot;
use App\Walleting\Message\OutboxEvent;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;

final readonly class InboxService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private Connection $connection,
    ) {
    }

    /**
     * Executes the handler once for a unique external event identity.
     *
     * The receipt insert is flushed before the handler inside the same transaction, so the database unique constraint
     * guards the side effect against concurrent duplicate deliveries of the same source/message_id.
     *
     * @param callable(OutboxEvent): void $handler
     */
    public function processOnce(OutboxEvent $event, callable $handler): bool
    {
        try {
            return $this->connection->transactional(function () use ($event, $handler): bool {
                $existing = $this->findReceipt($event);
                if ($existing instanceof InboxReceipt) {
                    $existing->assertSameEvent($event);

                    return false;
                }

                $receipt = new InboxReceipt($event);
                $this->entityManager->persist($receipt);
                $this->entityManager->flush();

                $handler($event);

                $receipt->markProcessed();
                $this->entityManager->flush();

                return true;
            });
        } catch (UniqueConstraintViolationException) {
            $this->entityManager->clear();
            $existing = $this->findReceipt($event);
            if (!$existing instanceof InboxReceipt) {
                throw new \RuntimeException('Duplicate inbox receipt conflict could not be resolved.');
            }
            $existing->assertSameEvent($event);

            return false;
        } catch (\Throwable $exception) {
            $this->entityManager->clear();
            throw $exception;
        }
    }

    public function healthSnapshot(int $stuckAfterSeconds): InboxHealthSnapshot
    {
        if ($stuckAfterSeconds < 1 || $stuckAfterSeconds > 86400) {
            throw new \InvalidArgumentException('Inbox stuck threshold must be between 1 and 86400 seconds.');
        }

        $statusCounts = [];
        foreach ($this->connection->fetchAllAssociative('SELECT status, COUNT(*) AS count FROM inbox_receipt GROUP BY status ORDER BY status') as $row) {
            $statusCounts[(string) $row['status']] = (int) $row['count'];
        }

        $oldestAge = $this->connection->fetchOne("SELECT EXTRACT(EPOCH FROM (CURRENT_TIMESTAMP - MIN(received_at)))::INT FROM inbox_receipt WHERE status = 'processing'");
        $stuckCount = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM inbox_receipt WHERE status = 'processing' AND received_at < CURRENT_TIMESTAMP - (? * INTERVAL '1 second')",
            [$stuckAfterSeconds],
            [ParameterType::INTEGER],
        );

        return new InboxHealthSnapshot(
            $statusCounts,
            false === $oldestAge || null === $oldestAge ? null : (int) $oldestAge,
            $stuckCount,
        );
    }

    /** @return list<array<string, mixed>> */
    public function stuckProcessing(int $stuckAfterSeconds, int $limit): array
    {
        if ($stuckAfterSeconds < 1 || $stuckAfterSeconds > 86400 || $limit < 1 || $limit > 500) {
            throw new \InvalidArgumentException('Inbox diagnostics bounds are invalid.');
        }

        return $this->connection->fetchAllAssociative(
            "SELECT id, source, message_id, schema_version, event_type, deduplication_key, received_at FROM inbox_receipt WHERE status = 'processing' AND received_at < CURRENT_TIMESTAMP - (? * INTERVAL '1 second') ORDER BY received_at, id LIMIT ?",
            [$stuckAfterSeconds, $limit],
            [ParameterType::INTEGER, ParameterType::INTEGER],
        );
    }

    /** @return list<array<string, mixed>> */
    public function receiptDiagnostics(string $source, string $messageId): array
    {
        $source = trim($source);
        $messageId = trim($messageId);
        if ('' === $source || '' === $messageId) {
            throw new \InvalidArgumentException('Inbox diagnostic source and message id are required.');
        }

        return $this->connection->fetchAllAssociative(
            'SELECT id, source, message_id, schema_version, event_type, deduplication_key, payload_hash, status, received_at, processed_at FROM inbox_receipt WHERE source = ? AND message_id = ? LIMIT 1',
            [$source, $messageId],
        );
    }

    public function cleanupProcessed(int $retentionDays, int $limit): int
    {
        if ($retentionDays < 1 || $retentionDays > 3650) {
            throw new \InvalidArgumentException('Inbox retention must be between 1 and 3650 days.');
        }
        if ($limit < 1 || $limit > 500) {
            throw new \InvalidArgumentException('Inbox cleanup limit must be between 1 and 500.');
        }

        return $this->entityManager->wrapInTransaction(fn (): int => $this->connection->executeStatement(
            "DELETE FROM inbox_receipt WHERE id IN (SELECT id FROM inbox_receipt WHERE status = 'processed' AND processed_at < CURRENT_TIMESTAMP - (? * INTERVAL '1 day') ORDER BY processed_at, id FOR UPDATE SKIP LOCKED LIMIT ?)",
            [$retentionDays, $limit],
            [ParameterType::INTEGER, ParameterType::INTEGER],
        ));
    }

    private function findReceipt(OutboxEvent $event): ?InboxReceipt
    {
        $receipt = $this->entityManager->getRepository(InboxReceipt::class)->findOneBy([
            'source' => $event->source,
            'messageId' => $event->messageId,
        ]);

        return $receipt instanceof InboxReceipt ? $receipt : null;
    }
}
