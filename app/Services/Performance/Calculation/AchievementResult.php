<?php

declare(strict_types=1);

namespace App\Services\Performance\Calculation;

/**
 * Outcome of one KPI achievement calculation, with everything needed to show
 * "how this was calculated". `achievement` is a percentage string (4 places)
 * or null when it cannot be computed (no actual, invalid target...).
 */
final readonly class AchievementResult
{
    public const OK = 'OK';

    public const NO_ACTUAL = 'NO_ACTUAL';

    public const INVALID_TARGET = 'INVALID_TARGET';

    public const INVALID_ACTUAL = 'INVALID_ACTUAL';

    public function __construct(
        public ?string $achievement,
        public string $status,
        public string $formula,
        public ?string $rawAchievement = null,
        public ?string $cap = null,
        public bool $capped = false,
    ) {}

    /** Achievement counted in a weighted score: a missing value counts as 0. */
    public function scoringValue(): string
    {
        return $this->achievement ?? '0.0000';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'achievement' => $this->achievement,
            'raw_achievement' => $this->rawAchievement,
            'status' => $this->status,
            'formula' => $this->formula,
            'cap' => $this->cap,
            'capped' => $this->capped,
        ];
    }
}
