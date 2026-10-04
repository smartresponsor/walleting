<?php

declare(strict_types=1);

namespace App\Walleting\Service;

use App\Walleting\ValueObject\Posting\WalletPostingHealthStatus;
use App\Walleting\ValueObject\Posting\WalletPostingSloStateTransition;
use App\Walleting\ValueObject\Posting\WalletPostingSloTrendAssessment;
use Doctrine\DBAL\Connection;

final readonly class WalletPostingSloStateService
{
    public function __construct(
        private Connection $connection,
        private WalletOutboxService $outboxService,
    ) {
    }

    public function apply(
        string $scope,
        WalletPostingSloTrendAssessment $assessment,
        int $breachEvaluations = 2,
        int $recoveryEvaluations = 3,
    ): WalletPostingSloStateTransition {
        $scope = $this->validatedScope($scope);
        $this->assertValidHysteresis($breachEvaluations, $recoveryEvaluations);

        return $this->connection->transactional(
            fn (Connection $connection): WalletPostingSloStateTransition => $this->applyLocked(
                $connection,
                $scope,
                $assessment,
                $breachEvaluations,
                $recoveryEvaluations,
            ),
        );
    }

    private function validatedScope(string $scope): string
    {
        $scope = trim($scope);
        if ('' === $scope || strlen($scope) > 64) {
            throw new \InvalidArgumentException('Posting SLO state scope must be between 1 and 64 characters.');
        }

        return $scope;
    }

    private function assertValidHysteresis(int $breachEvaluations, int $recoveryEvaluations): void
    {
        if ($breachEvaluations < 1 || $breachEvaluations > 100 || $recoveryEvaluations < 1 || $recoveryEvaluations > 100) {
            throw new \InvalidArgumentException('Posting SLO hysteresis counts must be between 1 and 100.');
        }
    }

    private function applyLocked(
        Connection $connection,
        string $scope,
        WalletPostingSloTrendAssessment $assessment,
        int $breachEvaluations,
        int $recoveryEvaluations,
    ): WalletPostingSloStateTransition {
        $row = $this->lockedStateRow($connection, $scope);
        $previous = WalletPostingHealthStatus::from((string) $row['status']);
        $observed = $assessment->status;
        $pending = null === $row['pending_status'] ? null : WalletPostingHealthStatus::from((string) $row['pending_status']);
        [$current, $pending, $pendingCount, $requiredCount, $changed] = $this->transitionState(
            $previous,
            $observed,
            $pending,
            (int) $row['pending_count'],
            $breachEvaluations,
            $recoveryEvaluations,
        );
        $revision = (int) $row['revision'];
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        if ($changed) {
            ++$revision;
        }

        $this->persistState($connection, $scope, $assessment, $current, $pending, $pendingCount, $revision, $changed, $now);
        if ($changed) {
            $this->enqueueTransition($scope, $assessment, $previous, $current, $revision, $now);
        }

        return new WalletPostingSloStateTransition(
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
    }

    /** @return array<string, mixed> */
    private function lockedStateRow(Connection $connection, string $scope): array
    {
        $row = $connection->fetchAssociative('SELECT scope, status, pending_status, pending_count, revision, reasons FROM posting_slo_state WHERE scope = ? FOR UPDATE', [$scope]);
        if (false !== $row) {
            return $row;
        }

        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $connection->executeStatement(
            "INSERT INTO posting_slo_state (scope, status, pending_status, pending_count, revision, reasons, evaluated_at, changed_at) VALUES (?, 'healthy', NULL, 0, 0, '[]', ?, ?) ON CONFLICT (scope) DO NOTHING",
            [$scope, $now, $now],
        );
        $row = $connection->fetchAssociative('SELECT scope, status, pending_status, pending_count, revision, reasons FROM posting_slo_state WHERE scope = ? FOR UPDATE', [$scope]);
        if (false === $row) {
            throw new \RuntimeException('Posting SLO state could not be initialized.');
        }

        return $row;
    }

    /** @return array{WalletPostingHealthStatus, ?WalletPostingHealthStatus, int, int, bool} */
    private function transitionState(
        WalletPostingHealthStatus $previous,
        WalletPostingHealthStatus $observed,
        ?WalletPostingHealthStatus $pending,
        int $pendingCount,
        int $breachEvaluations,
        int $recoveryEvaluations,
    ): array {
        $requiredCount = $this->severity($observed) > $this->severity($previous) ? $breachEvaluations : $recoveryEvaluations;
        if ($observed === $previous) {
            return [$previous, null, 0, 0, false];
        }

        if ($pending !== $observed) {
            $pending = $observed;
            $pendingCount = 1;
        } else {
            ++$pendingCount;
        }

        if ($pendingCount < $requiredCount) {
            return [$previous, $pending, $pendingCount, $requiredCount, false];
        }

        return [$observed, null, 0, $requiredCount, true];
    }

    private function persistState(
        Connection $connection,
        string $scope,
        WalletPostingSloTrendAssessment $assessment,
        WalletPostingHealthStatus $current,
        ?WalletPostingHealthStatus $pending,
        int $pendingCount,
        int $revision,
        bool $changed,
        string $now,
    ): void {
        $values = [
            'status' => $current->value,
            'pending_status' => $pending?->value,
            'pending_count' => $pendingCount,
            'reasons' => json_encode($assessment->reasons, JSON_THROW_ON_ERROR),
            'evaluated_at' => $now,
        ];
        if ($changed) {
            $values['revision'] = $revision;
            $values['changed_at'] = $now;
        }
        $connection->update('posting_slo_state', $values, ['scope' => $scope]);
    }

    private function enqueueTransition(
        string $scope,
        WalletPostingSloTrendAssessment $assessment,
        WalletPostingHealthStatus $previous,
        WalletPostingHealthStatus $current,
        int $revision,
        string $now,
    ): void {
        $this->outboxService->enqueueOperationalDbal(
            'posting.slo.state.changed',
            sprintf('posting.slo.state.changed:%s:%d', $scope, $revision),
            [
                'scope' => $scope,
                'revision' => $revision,
                'previous_status' => $previous->value,
                'current_status' => $current->value,
                'reasons' => $assessment->reasons,
                'changed_at' => $now,
            ],
        );
    }

    private function severity(WalletPostingHealthStatus $status): int
    {
        return match ($status) {
            WalletPostingHealthStatus::Healthy => 0,
            WalletPostingHealthStatus::Degraded => 1,
            WalletPostingHealthStatus::Critical => 2,
        };
    }
}
