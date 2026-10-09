<?php

declare(strict_types=1);

namespace App\Services\FieldWork;

use App\Models\Employee;
use Illuminate\Support\Carbon;

/**
 * Safe adapter boundary for a future attendance module. EUISIS has no
 * attendance summary or biometric model today, so this service never writes
 * an inferred attendance record and never manufactures biometric evidence.
 */
final class AttendanceReconciliationService
{
    public function __construct(private readonly EmployeeAvailabilityService $availability) {}

    /** @return array{state:string,attendance_status:?string,status_source:?string,reference_type:?string,reference_id:?string,intervals:list<array{from:string,to:string,reference_id:string,status:string}>,reason:string} */
    public function reconcileEmployeeDate(Employee $employee, Carbon $date): array
    {
        $dayStart = $date->copy()->startOfDay();
        $dayEnd = $date->copy()->endOfDay();
        $sessions = $this->availability->fieldWorkSessionsForDate($employee, $date);
        $session = $sessions->first();
        $intervals = $sessions->map(fn ($row): array => [
            'from' => $row->attendance_from?->max($dayStart)->toIso8601String() ?? $row->approved_start_at->max($dayStart)->toIso8601String(),
            'to' => $row->attendance_to?->min($dayEnd)->toIso8601String() ?? $row->approved_end_at->min($dayEnd)->toIso8601String(),
            'reference_id' => $row->field_work_request_id,
            'status' => $row->status->value,
        ])->all();

        return [
            'state' => 'needs_decision',
            'attendance_status' => $sessions->contains(fn ($row): bool => $row->checked_in_at !== null) ? 'official_field_work' : null,
            'status_source' => $session !== null ? 'field_work' : null,
            'reference_type' => $session !== null ? 'field_work_request' : null,
            'reference_id' => $session?->field_work_request_id,
            'intervals' => $intervals,
            'reason' => 'No authoritative attendance summary or biometric integration is installed; no attendance row was changed.',
        ];
    }
}
