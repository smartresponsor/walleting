<?php

declare(strict_types=1);

namespace App\Walleting\Service;

use App\Walleting\Entity\WalletOutboxMessage;
use App\Walleting\Entity\WalletOutboxRequeueAudit;
use App\Walleting\Enum\WalletOutboxMessageStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class WalletOutboxDeadLetterService
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function requeue(string $messageId, string $operator, string $reason): WalletOutboxMessage
    {
        $operator = trim($operator);
        $reason = trim($reason);
        if ('' === $operator || '' === $reason) {
            throw new \InvalidArgumentException('Outbox requeue operator and reason are required.');
        }

        return $this->entityManager->wrapInTransaction(function () use ($messageId, $operator, $reason): WalletOutboxMessage {
            $message = $this->entityManager->find(WalletOutboxMessage::class, Uuid::fromString($messageId), \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
            if (!$message instanceof WalletOutboxMessage) {
                throw new \RuntimeException('Outbox message was not found.');
            }
            if (WalletOutboxMessageStatus::Dead !== $message->status()) {
                throw new \LogicException('Only dead outbox messages can be requeued.');
            }

            $audit = new WalletOutboxRequeueAudit($message, $message->attemptCount(), $operator, $reason, $message->lastError());
            $message->requeueDead();
            $this->entityManager->persist($audit);
            $this->entityManager->flush();

            return $message;
        });
    }
}
