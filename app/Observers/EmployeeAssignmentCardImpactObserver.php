<?php

namespace App\Observers;

use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\Organization;
use App\Models\Position;
use App\Services\IdCards\IdCardSnapshotComparisonService;

class EmployeeAssignmentCardImpactObserver
{
    public function updated(EmployeeAssignment|Organization|Position $model): void
    {
        $query = Employee::query();
        if ($model instanceof EmployeeAssignment) {
            $query->where('current_assignment_id', $model->id);
        } elseif ($model instanceof Organization) {
            if (! $model->wasChanged(['name_en', 'name_am'])) return;
            $query->whereHas('currentAssignment', fn ($q) => $q->where('organization_id', $model->id));
        } else {
            if (! $model->wasChanged(['title_en', 'title_am'])) return;
            $query->whereHas('currentAssignment', fn ($q) => $q->where('position_id', $model->id));
        }
        $query->chunkById(100, function ($employees) {
            foreach ($employees as $employee) app(IdCardSnapshotComparisonService::class)->evaluate($employee);
        });
    }
}
