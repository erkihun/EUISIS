<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\EmployeeAssignment;
use App\Models\PositionEstablishment;
use Illuminate\Support\Carbon;

/**
 * The one capacity rule: a position seats the approved slots of its establishments
 * in force on the day, or one person when it has none; holders are the active
 * current placements.
 */
final class SeatCapacity
{
    public function capacity(string $positionId, ?Carbon $on = null): int
    {
        $day = ($on ?? now())->toDateString();
        $approved = (int) PositionEstablishment::query()
            ->where('position_id', $positionId)
            ->where('status', PositionEstablishment::APPROVED)
            ->whereDate('effective_from', '<=', $day)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $day))
            ->sum('approved_slots');

        return $approved > 0 ? $approved : 1;
    }

    public function holders(string $positionId): int
    {
        return EmployeeAssignment::query()
            ->where('position_id', $positionId)
            ->where('is_current', true)
            ->where('assignment_status', EmployeeAssignment::ACTIVE)
            ->count();
    }

    public function hasFreeSeat(string $positionId, ?Carbon $on = null): bool
    {
        return $this->holders($positionId) < $this->capacity($positionId, $on);
    }
}
