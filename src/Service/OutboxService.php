<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\LedgerTransaction;
use App\Entity\OutboxMessage;
use App\Entity\ProviderEvent;
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

    /** @return list<OutboxMessage> */
    public function claimBatch(int $limit): array
    {
        if ($limit < 1 || $limit > 500) {
            throw new \InvalidArgumentException('Outbox claim limit must be between 1 and 500.');
        }

        return $this->entityManager->wrapInTransaction(function () use ($limit): array {
            $rows = $this->connection->fetchFirstColumn(
                "SELECT id FROM outbox_message WHERE status IN ('pending', 'failed') AND available_at <= CURRENT_TIMESTAMP ORDER BY created_at FOR UPDATE SKIP LOCKED LIMIT ?",
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
}
