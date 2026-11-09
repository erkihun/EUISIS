<?php

declare(strict_types=1);

namespace App\Backbone\Contracts;

use App\Backbone\Data\PositionData;
use App\Backbone\Data\PositionOccupancyData;
use App\Models\User;

/**
 * Positions and their seats. Occupancy follows the repository's one capacity
 * rule (PositionCapacityService) and is never re-derived elsewhere.
 */
interface PositionDirectory
{
    public function find(string $positionUuid): ?PositionData;

    public function findFor(User $viewer, string $positionUuid): ?PositionData;

    /** Seats, held seats and whether the position is in use; null for an unknown position. */
    public function occupancy(string $positionUuid): ?PositionOccupancyData;

    public function hasVacancy(string $positionUuid): bool;
}
