<?php

declare(strict_types=1);

namespace App\Walleting\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'outbox_requeue_audit')]
#[ORM\Index(name: 'idx_outbox_requeue_message_created', columns: ['outbox_message_id', 'created_at'])]
final class OutboxRequeueAudit
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: OutboxMessage::class)]
    #[ORM\JoinColumn(name: 'outbox_message_id', nullable: false, onDelete: 'RESTRICT')]
    private OutboxMessage $outboxMessage;

    #[ORM\Column(name: 'attempt_count')]
    private int $attemptCount;

    #[ORM\Column(length: 191)]
    private string $operator;

    #[ORM\Column(type: 'text')]
    private string $reason;

    #[ORM\Column(name: 'previous_error', type: 'text', nullable: true)]
    private ?string $previousError;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(OutboxMessage $outboxMessage, int $attemptCount, string $operator, string $reason, ?string $previousError)
    {
        $operator = trim($operator);
        $reason = trim($reason);
        if ($attemptCount < 1 || '' === $operator || '' === $reason) {
            throw new \InvalidArgumentException('Outbox requeue audit values are invalid.');
        }

        $this->id = Uuid::v7();
        $this->outboxMessage = $outboxMessage;
        $this->attemptCount = $attemptCount;
        $this->operator = $operator;
        $this->reason = $reason;
        $this->previousError = null === $previousError ? null : trim($previousError);
        $this->createdAt = new \DateTimeImmutable();
    }
}
