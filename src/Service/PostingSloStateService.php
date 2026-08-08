<?php

declare(strict_types=1);

namespace App\Service;

use App\Posting\PostingHealthStatus;
use App\Posting\PostingSloStateTransition;
use App\Posting\PostingSloTrendAssessment;
use Doctrine\DBAL\Connection;

final readonly class PostingSloStateService
{
    public function __construct(private Connection $connection)
    {
    }

    public function apply(
        string $scope,
        PostingSloTrendAssessment $assessment,
        int $breachEvaluations = 2,
        int $recoveryEvaluations = 3,
    ): PostingSloStateTransition {
        $scope = trim($scope);
        if ('' === $scope || strlen($scope) > 64) {
            throw new \InvalidArgumentException('Posting SLO state scope must be between 1 and 64 characters.');
        }
        if ($breachEvaluations < 1 || $breachEvaluations > 100 || $recoveryEvaluations < 1 || $recoveryEvaluations > 100) {
            throw new \InvalidArgumentException('Posting SLO hysteresis counts must be between 1 and 100.');
        }

        return $this->connection->transactional(function (Connection $connection) use ($scope, $assessment, $breachEvaluations, $recoveryEvaluations): PostingSloStateTransition {
            $row = $connection->fetchAssociative('SELECT scope, status, pending_status, pending_count, reasons FROM posting_slo_state WHERE scope = ? FOR UPDATE', [$scope]);
            if (false === $row) {
                $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
                $connection->executeStatement(
                    "INSERT INTO posting_slo_state (scope, status, pending_status, pending_count, reasons, evaluated_at, changed_at) VALUES (?, 'healthy', NULL, 0, '[]', ?, ?) ON CONFLICT (scope) DO NOTHING",
                    [$scope, $now, $now],
                );
                $row = $connection->fetchAssociative('SELECT scope, status, pending_status, pending_count, reasons FROM posting_slo_state WHERE scope = ? FOR UPDATE', [$scope]);
                if (false === $row) {
                    throw new \RuntimeException('Posting SLO state could not be initialized.');
                }
            }

            $previous = PostingHealthStatus::from((string) $row['status']);
            $observed = $assessment->status;
            $pending = null === $row['pending_status'] ? null : PostingHealthStatus::from((string) $row['pending_status']);
            $pendingCount = (int) $row['pending_count'];
            $requiredCount = $this->severity($observed) > $this->severity($previous) ? $breachEvaluations : $recoveryEvaluations;
            $changed = false;
            $current = $previous;

            if ($observed === $previous) {
                $pending = null;
                $pendingCount = 0;
                $requiredCount = 0;
            } else {
                if ($pending !== $observed) {
                    $pending = $observed;
                    $pendingCount = 1;
                } else {
                    ++$pendingCount;
                }

                if ($pendingCount >= $requiredCount) {
                    $current = $observed;
                    $pending = null;
                    $pendingCount = 0;
                    $changed = true;
                }
            }

            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            $values = [
                'status' => $current->value,
                'pending_status' => $pending?->value,
                'pending_count' => $pendingCount,
                'reasons' => json_encode($assessment->reasons, JSON_THROW_ON_ERROR),
                'evaluated_at' => $now,
            ];
            if ($changed) {
                $values['changed_at'] = $now;
            }
            $connection->update('posting_slo_state', $values, ['scope' => $scope]);

            return new PostingSloStateTransition(
                $scope,
                $previous,
                $current,
                $observed,
                $pending,
                $pendingCount,
                $requiredCount,
                $changed,
                $assessment->reasons,
            );
        });
    }

    private function severity(PostingHealthStatus $status): int
    {
        return match ($status) {
            PostingHealthStatus::Healthy => 0,
            PostingHealthStatus::Degraded => 1,
            PostingHealthStatus::Critical => 2,
        };
    }
}
