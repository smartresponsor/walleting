<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\LedgerTransaction;
use App\Entity\OutboxMessage;
use App\Entity\ProviderEvent;
use App\Outbox\OutboxHealthSnapshot;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class OutboxService
{
    public function __construct(private EntityManagerInterface $entityManager, private Connection $connection)
    {
    }

    public function enqueueManaged(
        string $messageType,
        string $deduplicationKey,
        array $payload,
        ?LedgerTransaction $ledgerTransaction = null,
        ?ProviderEvent $providerEvent = null,
        ?\DateTimeImmutable $availableAt = null,
    ): OutboxMessage {
        $existing = $this->entityManager->getRepository(OutboxMessage::class)->findOneBy(['deduplicationKey' => trim($deduplicationKey)]);
        if ($existing instanceof OutboxMessage) {
            $candidate = new OutboxMessage($messageType, $deduplicationKey, $payload, $ledgerTransaction, $providerEvent, $availableAt);
            if (
                $existing->messageType() !== $candidate->messageType()
                || !hash_equals($existing->payloadHash(), $candidate->payloadHash())
                || $existing->ledgerTransaction()?->id()->toRfc4122() !== $candidate->ledgerTransaction()?->id()->toRfc4122()
                || $existing->providerEvent()?->externalId() !== $candidate->providerEvent()?->externalId()
            ) {
                throw new \DomainException('Outbox deduplication key is already bound to different message content.');
            }

            return $existing;
        }

        $message = new OutboxMessage($messageType, $deduplicationKey, $payload, $ledgerTransaction, $providerEvent, $availableAt);
        $this->entityManager->persist($message);

        return $message;
    }

    public function enqueueLedgerTransactionPostedDbal(
        string $transactionId,
        string $transactionType,
        string $idempotencyKey,
        string $requestHash,
        array $metadata,
    ): void {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('DBAL outbox enqueue requires an active database transaction.');
        }

        $transactionId = trim($transactionId);
        if ('' === $transactionId || '' === trim($transactionType) || '' === trim($idempotencyKey) || 1 !== preg_match('/^[a-f0-9]{64}$/', $requestHash)) {
            throw new \InvalidArgumentException('Posted outbox transaction identity is invalid.');
        }

        $payload = [
            'transaction_id' => $transactionId,
            'transaction_type' => $transactionType,
            'idempotency_key' => $idempotencyKey,
            'request_hash' => $requestHash,
            'metadata' => $metadata,
        ];
        $normalizedPayload = $this->normalizePayload($payload);
        $now = new \DateTimeImmutable();

        $this->connection->insert('outbox_message', [
            'id' => Uuid::v7()->toRfc4122(),
            'ledger_transaction_id' => $transactionId,
            'provider_event_id' => null,
            'message_type' => 'ledger.transaction.posted',
            'deduplication_key' => 'ledger.transaction.posted:'.$transactionId,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'payload_hash' => hash('sha256', json_encode($normalizedPayload, JSON_THROW_ON_ERROR)),
            'status' => 'pending',
            'attempt_count' => 0,
            'available_at' => $now->format('Y-m-d H:i:s'),
            'claimed_at' => null,
            'dispatched_at' => null,
            'last_error' => null,
            'created_at' => $now->format('Y-m-d H:i:s'),
        ]);
    }

    public function enqueueOperationalDbal(string $messageType, string $deduplicationKey, array $payload): void
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('DBAL operational outbox enqueue requires an active database transaction.');
        }

        $messageType = trim($messageType);
        $deduplicationKey = trim($deduplicationKey);
        if ('' === $messageType || '' === $deduplicationKey) {
            throw new \InvalidArgumentException('Operational outbox message type and deduplication key are required.');
        }

        $normalizedPayload = $this->normalizePayload($payload);
        $now = new \DateTimeImmutable();
        $this->connection->insert('outbox_message', [
            'id' => Uuid::v7()->toRfc4122(),
            'ledger_transaction_id' => null,
            'provider_event_id' => null,
            'message_type' => $messageType,
            'deduplication_key' => $deduplicationKey,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'payload_hash' => hash('sha256', json_encode($normalizedPayload, JSON_THROW_ON_ERROR)),
            'status' => 'pending',
            'attempt_count' => 0,
            'available_at' => $now->format('Y-m-d H:i:s'),
            'claimed_at' => null,
            'dispatched_at' => null,
            'last_error' => null,
            'created_at' => $now->format('Y-m-d H:i:s'),
        ]);
    }

    /** @return list<OutboxMessage> */
    public function claimBatch(int $limit): array
    {
        if ($limit < 1 || $limit > 500) {
            throw new \InvalidArgumentException('Outbox claim limit must be between 1 and 500.');
        }

        return $this->entityManager->wrapInTransaction(function () use ($limit): array {
            $rows = $this->connection->fetchFirstColumn(
                "SELECT id FROM outbox_message WHERE status IN ('pending', 'failed') AND available_at <= CURRENT_TIMESTAMP ORDER BY created_at, id FOR UPDATE SKIP LOCKED LIMIT ?",
                [$limit],
                [ParameterType::INTEGER],
            );

            $messages = [];
            foreach ($rows as $id) {
                $message = $this->entityManager->find(OutboxMessage::class, Uuid::fromString((string) $id));
                if (!$message instanceof OutboxMessage) {
                    throw new \RuntimeException('Claimed outbox message could not be loaded.');
                }
                $message->claim();
                $messages[] = $message;
            }
            $this->entityManager->flush();

            return $messages;
        });
    }

    public function healthSnapshot(): OutboxHealthSnapshot
    {
        $statusCounts = [];
        foreach ($this->connection->fetchAllAssociative('SELECT status, COUNT(*) AS count FROM outbox_message GROUP BY status ORDER BY status') as $row) {
            $statusCounts[(string) $row['status']] = (int) $row['count'];
        }

        $oldestAge = $this->connection->fetchOne("SELECT EXTRACT(EPOCH FROM (CURRENT_TIMESTAMP - MIN(created_at)))::INT FROM outbox_message WHERE status IN ('pending', 'failed') AND available_at <= CURRENT_TIMESTAMP");

        return new OutboxHealthSnapshot(
            $statusCounts,
            false === $oldestAge || null === $oldestAge ? null : (int) $oldestAge,
            $statusCounts['dead'] ?? 0,
        );
    }

    /** @return list<array<string, mixed>> */
    public function deadLetters(int $limit): array
    {
        if ($limit < 1 || $limit > 500) {
            throw new \InvalidArgumentException('Outbox dead-letter limit must be between 1 and 500.');
        }

        return $this->connection->fetchAllAssociative(
            "SELECT id, message_type, deduplication_key, attempt_count, last_error, claimed_at, created_at FROM outbox_message WHERE status = 'dead' ORDER BY claimed_at DESC, id DESC LIMIT ?",
            [$limit],
            [ParameterType::INTEGER],
        );
    }

    public function recoverStaleClaims(int $timeoutSeconds, int $limit): int
    {
        if ($timeoutSeconds < 1 || $timeoutSeconds > 86400) {
            throw new \InvalidArgumentException('Outbox claim timeout must be between 1 and 86400 seconds.');
        }
        if ($limit < 1 || $limit > 500) {
            throw new \InvalidArgumentException('Outbox recovery limit must be between 1 and 500.');
        }

        return $this->entityManager->wrapInTransaction(fn (): int => $this->connection->executeStatement(
            "UPDATE outbox_message SET status = 'failed', available_at = CURRENT_TIMESTAMP, last_error = 'Claim lease expired before acknowledgement.' WHERE id IN (SELECT id FROM outbox_message WHERE status = 'claimed' AND claimed_at < CURRENT_TIMESTAMP - (? * INTERVAL '1 second') ORDER BY claimed_at, id FOR UPDATE SKIP LOCKED LIMIT ?)",
            [$timeoutSeconds, $limit],
            [ParameterType::INTEGER, ParameterType::INTEGER],
        ));
    }

    public function markDispatched(OutboxMessage $message): void
    {
        $this->entityManager->wrapInTransaction(function () use ($message): void {
            $message->markDispatched();
            $this->entityManager->flush();
        });
    }

    public function markFailed(OutboxMessage $message, string $error, \DateTimeImmutable $availableAt): void
    {
        $this->entityManager->wrapInTransaction(function () use ($message, $error, $availableAt): void {
            $message->markFailed($error, $availableAt);
            $this->entityManager->flush();
        });
    }

    public function markTerminalFailure(OutboxMessage $message, string $error): void
    {
        $this->entityManager->wrapInTransaction(function () use ($message, $error): void {
            $message->markDead($error);
            $this->entityManager->flush();
        });
    }

    private function normalizePayload(array $value): array
    {
        ksort($value);
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = $this->normalizePayload($item);
            }
        }
        unset($item);

        return $value;
    }
}
