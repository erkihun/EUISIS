<?php

declare(strict_types=1);

namespace App\Services\Vacancy;

use App\Enums\EstablishmentStatus;
use App\Enums\OccupancyStatus;
use App\Models\EmployeeAssignment;
use App\Models\Position;
use App\Models\PositionEstablishment;
use App\Models\PositionOccupancy;
use Illuminate\Support\Carbon;

class PositionCapacityService
{
    public function approvedSlotsForPosition(string $positionId, ?Carbon $onDate = null): int
    {
        // Compared as a date: a timestamp would drop the last effective day on
        // databases that compare the date column as text.
        $day = ($onDate ?? now())->toDateString();

        return (int) PositionEstablishment::query()
            ->where('position_id', $positionId)
            ->where('status', EstablishmentStatus::Approved->value)
            ->whereDate('effective_from', '<=', $day)
            ->where(function ($q) use ($day): void {
                $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $day);
            })
            ->sum('approved_slots');
    }

    public function activeOccupanciesForPosition(string $positionId): int
    {
        return PositionOccupancy::query()
            ->where('position_id', $positionId)
            ->where('status', OccupancyStatus::Active->value)
            ->count();
    }

    public function availableSlotsForPosition(string $positionId, ?Carbon $onDate = null): int
    {
        $approved = $this->approvedSlotsForPosition($positionId, $onDate);
        $occupied = $this->activeOccupanciesForPosition($positionId);

        return max(0, $approved - $occupied);
    }

    public function hasVacancy(string $positionId, ?Carbon $onDate = null): bool
    {
        return $this->availableSlotsForPosition($positionId, $onDate) > 0;
    }

    public function recordOccupancy(
        PositionEstablishment $establishment,
        EmployeeAssignment $assignment,
        Carbon $occupiedFrom,
    ): PositionOccupancy {
        return PositionOccupancy::create([
            'position_establishment_id' => $establishment->id,
            'employee_id' => $assignment->employee_id,
            'employee_assignment_id' => $assignment->id,
            'organization_id' => $assignment->organization_id,
            'position_id' => $assignment->position_id,
            'occupied_from' => $occupiedFrom,
            'status' => OccupancyStatus::Active->value,
        ]);
    }

    public function releaseOccupancy(PositionOccupancy $occupancy, string $reason, Carbon $releasedOn): void
    {
        $occupancy->update([
            'status' => OccupancyStatus::Released->value,
            'occupied_until' => $releasedOn,
            'release_reason' => $reason,
        ]);
    }

    public function releaseOccupancyForAssignment(string $assignmentId, string $reason, Carbon $releasedOn): void
    {
        PositionOccupancy::query()
            ->where('employee_assignment_id', $assignmentId)
            ->where('status', OccupancyStatus::Active->value)
            ->each(fn (PositionOccupancy $o) => $this->releaseOccupancy($o, $reason, $releasedOn));
    }

    public function backfillOccupanciesForPosition(Position $position, bool $dryRun = false): array
    {
        $establishment = PositionEstablishment::query()
            ->where('position_id', $position->id)
            ->where('status', EstablishmentStatus::Approved->value)
            ->latest('effective_from')
            ->first();

        if ($establishment === null) {
            return ['skipped' => true, 'reason' => 'no_establishment'];
        }

        $assignments = EmployeeAssignment::query()
            ->where('position_id', $position->id)
            ->where('is_current', true)
            ->get();

        $created = [];

        foreach ($assignments as $assignment) {
            $exists = PositionOccupancy::query()
                ->where('employee_assignment_id', $assignment->id)
                ->where('status', OccupancyStatus::Active->value)
                ->exists();

            if ($exists) {
                continue;
            }

            $created[] = $assignment->id;

            if (! $dryRun) {
                $this->recordOccupancy($establishment, $assignment, $assignment->effective_from ?? now()->startOfDay());
            }
        }

        return ['dry_run' => $dryRun, 'created' => $created, 'count' => count($created)];
    }
}
