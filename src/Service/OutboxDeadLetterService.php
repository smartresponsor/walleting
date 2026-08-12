<?php

declare(strict_types=1);

namespace App\Walleting\Service;

use App\Walleting\Entity\OutboxMessage;
use App\Walleting\Entity\OutboxRequeueAudit;
use App\Walleting\Enum\OutboxMessageStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class OutboxDeadLetterService
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function requeue(string $messageId, string $operator, string $reason): OutboxMessage
    {
        $operator = trim($operator);
        $reason = trim($reason);
        if ('' === $operator || '' === $reason) {
            throw new \InvalidArgumentException('Outbox requeue operator and reason are required.');
        }

        return $this->entityManager->wrapInTransaction(function () use ($messageId, $operator, $reason): OutboxMessage {
            $message = $this->entityManager->find(OutboxMessage::class, Uuid::fromString($messageId), \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
            if (!$message instanceof OutboxMessage) {
                throw new \RuntimeException('Outbox message was not found.');
            }
            if (OutboxMessageStatus::Dead !== $message->status()) {
                throw new \LogicException('Only dead outbox messages can be requeued.');
            }

            $audit = new OutboxRequeueAudit($message, $message->attemptCount(), $operator, $reason, $message->lastError());
            $message->requeueDead();
            $this->entityManager->persist($audit);
            $this->entityManager->flush();

            return $message;
        });
    }
}
