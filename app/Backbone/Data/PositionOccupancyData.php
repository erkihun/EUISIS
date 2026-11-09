<?php

declare(strict_types=1);

namespace App\Backbone\Data;

/**
 * How many seats a position has and how many are held, by the repository's
 * one capacity rule (PositionCapacityService): the approved establishment
 * slots in force, or one seat when none are approved, against the current
 * active assignments. Counts only; never who holds a seat.
 */
final readonly class PositionOccupancyData
{
    public function __construct(
        public string $positionUuid,
        public int $capacity,
        public int $occupied,
        public bool $isActive,
    ) {}

    public function vacant(): int
    {
        return max(0, $this->capacity - $this->occupied);
    }

    public function hasVacancy(): bool
    {
        return $this->isActive && $this->vacant() > 0;
    }
}
