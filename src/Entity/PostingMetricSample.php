<?php

declare(strict_types=1);

namespace App\Walleting\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'posting_metric_sample')]
#[ORM\Index(name: 'idx_posting_metric_recorded_at', columns: ['recorded_at'])]
#[ORM\Index(name: 'idx_posting_metric_event_recorded_at', columns: ['event', 'recorded_at'])]
#[ORM\Index(name: 'idx_posting_metric_retry_reason_recorded_at', columns: ['retry_reason', 'recorded_at'])]
final class PostingMetricSample
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 16)]
    private string $event;

    #[ORM\Column(name: 'transaction_type', length: 32)]
    private string $transactionType;

    #[ORM\Column]
    private int $attempt;

    #[ORM\Column(name: 'retry_count')]
    private int $retryCount;

    #[ORM\Column(name: 'retry_reason', length: 64, nullable: true)]
    private ?string $retryReason;

    #[ORM\Column(name: 'attempt_duration_ms')]
    private int $attemptDurationMilliseconds;

    #[ORM\Column(name: 'total_duration_ms')]
    private int $totalDurationMilliseconds;

    #[ORM\Column(name: 'lock_wait_ms', nullable: true)]
    private ?int $lockWaitMilliseconds;

    #[ORM\Column(name: 'recorded_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $recordedAt;

    public function __construct(
        string $event,
        string $transactionType,
        int $attempt,
        int $retryCount,
        ?string $retryReason,
        int $attemptDurationMilliseconds,
        int $totalDurationMilliseconds,
        ?int $lockWaitMilliseconds,
        ?\DateTimeImmutable $recordedAt = null,
    ) {
        $this->id = Uuid::v7();
        $this->event = $event;
        $this->transactionType = $transactionType;
        $this->attempt = $attempt;
        $this->retryCount = $retryCount;
        $this->retryReason = $retryReason;
        $this->attemptDurationMilliseconds = $attemptDurationMilliseconds;
        $this->totalDurationMilliseconds = $totalDurationMilliseconds;
        $this->lockWaitMilliseconds = $lockWaitMilliseconds;
        $this->recordedAt = $recordedAt ?? new \DateTimeImmutable();
    }
}
