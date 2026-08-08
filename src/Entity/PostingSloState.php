<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'posting_slo_state')]
final class PostingSloState
{
    #[ORM\Id]
    #[ORM\Column(length: 64)]
    private string $scope;

    #[ORM\Column(length: 16)]
    private string $status;

    #[ORM\Column(name: 'pending_status', length: 16, nullable: true)]
    private ?string $pendingStatus;

    #[ORM\Column(name: 'pending_count')]
    private int $pendingCount;

    #[ORM\Column]
    private int $revision;

    #[ORM\Column(type: 'json')]
    private array $reasons;

    #[ORM\Column(name: 'evaluated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $evaluatedAt;

    #[ORM\Column(name: 'changed_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $changedAt;

    public function __construct(string $scope)
    {
        $now = new \DateTimeImmutable();
        $this->scope = $scope;
        $this->status = 'healthy';
        $this->pendingStatus = null;
        $this->pendingCount = 0;
        $this->revision = 0;
        $this->reasons = [];
        $this->evaluatedAt = $now;
        $this->changedAt = $now;
    }
}
