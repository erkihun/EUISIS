<?php

declare(strict_types=1);

namespace App\Services\Cafeteria;

use App\Enums\CafeteriaExclusionStatus;
use App\Models\Employee;
use App\Models\EmployeeCafeteriaExclusion;
use Illuminate\Support\Carbon;

class CafeteriaEmployeeExclusionService
{
    public function isExcluded(Employee $employee, Carbon $date): bool
    {
        return EmployeeCafeteriaExclusion::query()
            ->where('employee_id', $employee->id)
            ->where('status', CafeteriaExclusionStatus::Active->value)
            ->whereNull('deleted_at')
            ->where('starts_on', '<=', $date->toDateString())
            ->where(function ($q) use ($date): void {
                $q->whereNull('ends_on')
                    ->orWhere('ends_on', '>=', $date->toDateString());
            })
            ->where(function ($q) use ($date): void {
                $q->whereNull('return_to_work_on')
                    ->orWhere('return_to_work_on', '>', $date->toDateString());
            })
            ->exists();
    }

    public function getActiveExclusion(Employee $employee, Carbon $date): ?EmployeeCafeteriaExclusion
    {
        return EmployeeCafeteriaExclusion::query()
            ->where('employee_id', $employee->id)
            ->where('status', CafeteriaExclusionStatus::Active->value)
            ->whereNull('deleted_at')
            ->where('starts_on', '<=', $date->toDateString())
            ->where(function ($q) use ($date): void {
                $q->whereNull('ends_on')
                    ->orWhere('ends_on', '>=', $date->toDateString());
            })
            ->where(function ($q) use ($date): void {
                $q->whereNull('return_to_work_on')
                    ->orWhere('return_to_work_on', '>', $date->toDateString());
            })
            ->first();
    }

    public function hasOverlappingActive(Employee $employee, Carbon $startsOn, ?Carbon $endsOn, ?string $excludeId = null): bool
    {
        $query = EmployeeCafeteriaExclusion::query()
            ->where('employee_id', $employee->id)
            ->where('status', CafeteriaExclusionStatus::Active->value)
            ->whereNull('deleted_at')
            ->where(function ($q) use ($startsOn, $endsOn): void {
                $q->where(function ($inner) use ($startsOn): void {
                    // Existing starts_on <= new starts_on <= existing ends_on (or open-ended)
                    $inner->where('starts_on', '<=', $startsOn->toDateString())
                        ->where(function ($q2) use ($startsOn): void {
                            $q2->whereNull('ends_on')
                                ->orWhere('ends_on', '>=', $startsOn->toDateString());
                        });
                });

                if ($endsOn) {
                    $q->orWhere(function ($inner) use ($endsOn): void {
                        // Existing starts_on <= new ends_on
                        $inner->where('starts_on', '<=', $endsOn->toDateString())
                            ->where(function ($q2) use ($endsOn): void {
                                $q2->whereNull('ends_on')
                                    ->orWhere('ends_on', '>=', $endsOn->toDateString());
                            });
                    });
                }
            });

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->exists();
    }
}
