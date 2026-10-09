<?php

namespace App\Observers;

use App\Models\Employee;
use App\Models\User;
use App\Services\IdCards\IdCardSnapshotComparisonService;

class EmployeeCardImpactObserver
{
    public function updating(Employee $employee): void
    {
        // Pin legacy accounts before an HR/contact update can change the email join.
        if ($employee->isDirty('email') && $employee->getOriginal('email')) {
            User::query()->whereNull('employee_id')->where('email', $employee->getOriginal('email'))->update(['employee_id' => $employee->id]);
        }
    }

    public function updated(Employee $employee): void
    {
        app(IdCardSnapshotComparisonService::class)->evaluate($employee);
    }
}
