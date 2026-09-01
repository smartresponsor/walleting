<?php

declare(strict_types=1);

namespace App\Walleting\Service;

use App\Walleting\Entity\LedgerTransaction;
use App\Walleting\Entity\OutboxMessage;
use App\Walleting\Entity\ProviderEvent;
use App\Walleting\Outbox\OutboxHealthSnapshot;
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

    public function claimById(string $messageId): OutboxMessage
    {
        $messageId = trim($messageId);
        if ('' === $messageId) {
            throw new \InvalidArgumentException('Outbox message id is required.');
        }

        return $this->entityManager->wrapInTransaction(function () use ($messageId): OutboxMessage {
            $id = $this->connection->fetchOne(
                "SELECT id FROM outbox_message WHERE id = ? AND status IN ('pending', 'failed') AND available_at <= (CURRENT_TIMESTAMP AT TIME ZONE 'UTC') FOR UPDATE",
                [$messageId],
            );
            if (false === $id) {
                throw new \RuntimeException('Outbox message is not dispatchable.');
            }

            $message = $this->entityManager->find(OutboxMessage::class, Uuid::fromString((string) $id));
            if (!$message instanceof OutboxMessage) {
                throw new \RuntimeException('Selected outbox message could not be loaded.');
            }
            $message->claim();
            $this->entityManager->flush();

            return $message;
        });
    }

    /** @return list<OutboxMessage> */
    public function claimBatch(int $limit): array
    {
        if ($limit < 1 || $limit > 500) {
            throw new \InvalidArgumentException('Outbox claim limit must be between 1 and 500.');
        }

        return $this->entityManager->wrapInTransaction(function () use ($limit): array {
            $rows = $this->connection->fetchFirstColumn(
                "SELECT id FROM outbox_message WHERE status IN ('pending', 'failed') AND available_at <= (CURRENT_TIMESTAMP AT TIME ZONE 'UTC') ORDER BY created_at, id FOR UPDATE SKIP LOCKED LIMIT ?",
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

        $oldestAge = $this->connection->fetchOne("SELECT EXTRACT(EPOCH FROM ((CURRENT_TIMESTAMP AT TIME ZONE 'UTC') - MIN(created_at)))::INT FROM outbox_message WHERE status IN ('pending', 'failed') AND available_at <= (CURRENT_TIMESTAMP AT TIME ZONE 'UTC')");

        return new OutboxHealthSnapshot(
            $statusCounts,
            false === $oldestAge || null === $oldestAge ? null : (int) $oldestAge,
            $statusCounts['dead'] ?? 0,
        );
    }

    /** @return array<string, mixed> */
    public function inspect(string $messageId): array
    {
        $messageId = trim($messageId);
        if ('' === $messageId) {
            throw new \InvalidArgumentException('Outbox message id is required.');
        }

        $message = $this->connection->fetchAssociative(
            'SELECT id, ledger_transaction_id, provider_event_id, message_type, deduplication_key, payload, payload_hash, status, attempt_count, available_at, claimed_at, dispatched_at, last_error, created_at FROM outbox_message WHERE id = ? LIMIT 1',
            [$messageId],
        );
        if (false === $message) {
            throw new \RuntimeException('Outbox message was not found.');
        }

        $payload = json_decode((string) $message['payload'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new \RuntimeException('Outbox payload must decode to an object or array.');
        }
        $message['payload'] = $payload;
        $message['requeue_history'] = $this->connection->fetchAllAssociative(
            'SELECT id, attempt_count, operator, reason, previous_error, created_at FROM outbox_requeue_audit WHERE outbox_message_id = ? ORDER BY created_at, id',
            [$messageId],
        );

        return $message;
    }

    public function deadLetters(int $limit): array
    {
        if ($limit < 1 || $limit > 500) {
            throw new \InvalidArgumentException('Outbox dead-letter limit must be between 1 and 500.');
        }

        return $this->connection->fetchAllAssociative(
            "SELECT m.id, m.message_type, m.deduplication_key, m.attempt_count, m.last_error, m.claimed_at, m.created_at, EXTRACT(EPOCH FROM ((CURRENT_TIMESTAMP AT TIME ZONE 'UTC') - m.created_at))::INT AS age_seconds, COUNT(a.id)::INT AS requeue_count, MAX(a.created_at) AS last_requeued_at FROM outbox_message m LEFT JOIN outbox_requeue_audit a ON a.outbox_message_id = m.id WHERE m.status = 'dead' GROUP BY m.id, m.message_type, m.deduplication_key, m.attempt_count, m.last_error, m.claimed_at, m.created_at ORDER BY m.claimed_at DESC, m.id DESC LIMIT ?",
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

        return $this->entityManager->wrapInTransaction(fn (): int => (int) $this->connection->executeStatement(
            "UPDATE outbox_message SET status = 'failed', available_at = (CURRENT_TIMESTAMP AT TIME ZONE 'UTC'), last_error = 'Claim lease expired before acknowledgement.' WHERE id IN (SELECT id FROM outbox_message WHERE status = 'claimed' AND claimed_at < (CURRENT_TIMESTAMP AT TIME ZONE 'UTC') - (? * INTERVAL '1 second') ORDER BY claimed_at, id FOR UPDATE SKIP LOCKED LIMIT ?)",
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
