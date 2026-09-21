<?php

declare(strict_types=1);

namespace App\Walleting\ValueObject\Posting;

final readonly class WalletPostingSloStateTransition
{
    /** @param list<string> $reasons */
    public function __construct(
        public string $scope,
        public WalletPostingHealthStatus $previousStatus,
        public WalletPostingHealthStatus $currentStatus,
        public WalletPostingHealthStatus $observedStatus,
        public ?WalletPostingHealthStatus $pendingStatus,
        public int $pendingCount,
        public int $requiredCount,
        public bool $changed,
        public array $reasons,
    ) {
    }
}
