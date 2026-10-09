<?php

declare(strict_types=1);

namespace App\Services\FieldWork;

use App\Contracts\EmployeeLeaveProvider;
use App\Enums\FieldWorkStatus;
use App\Models\FieldWorkParticipant;
use Illuminate\Support\Carbon;

/**
 * Mutually exclusive official statuses that overlap a field work window.
 *
 * Checked at submission and again at approval (inside the approval lock),
 * and reported, never silently overwritten:
 *
 *   field_work  another pending / approved / in-field request of a participant
 *   leave       approved full-day leave from EmployeeLeaveProvider (the
 *               existing seam; EUISIS has no leave module yet, so the default
 *               binding reports none)
 *
 * NEEDS_DECISION: training and official travel/mission have no module or
 * provider yet; they join here when they exist.
 */
class FieldWorkConflictDetector
{
    public function __construct(
        private readonly EmployeeLeaveProvider $leave,
        private readonly FieldWorkSettings $settings,
    ) {}

    /**
     * @param  array<int, string>  $employeeIds
     * @return list<array{employee_id: string, kind: string, reference: ?string}>
     */
    public function conflicts(array $employeeIds, Carbon $from, Carbon $to, ?string $ignoreRequestId = null): array
    {
        if ($employeeIds === []) {
            return [];
        }

        $conflicts = [];

        FieldWorkParticipant::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereHas('request', fn ($query) => $query
                ->when($ignoreRequestId, fn ($q) => $q->whereKeyNot($ignoreRequestId))
                ->whereIn('status', [
                    FieldWorkStatus::PendingSupervisorApproval->value,
                    FieldWorkStatus::Approved->value,
                    FieldWorkStatus::InField->value,
                ])
                ->overlapping($from, $to))
            ->with('request:id,reference_number')
            ->limit(50)
            ->get(['id', 'employee_id', 'field_work_request_id'])
            ->each(function (FieldWorkParticipant $participant) use (&$conflicts): void {
                $conflicts[] = ['employee_id' => $participant->employee_id, 'kind' => 'field_work', 'reference' => $participant->request?->reference_number];
            });

        $timezone = $this->settings->timezone();
        $leave = $this->leave->fullDayLeaveDates(
            $employeeIds,
            $from->copy()->setTimezone($timezone)->startOfDay(),
            $to->copy()->setTimezone($timezone)->startOfDay(),
        );
        foreach ($leave as $employeeId => $dates) {
            if ($dates !== []) {
                $conflicts[] = ['employee_id' => (string) $employeeId, 'kind' => 'leave', 'reference' => null];
            }
        }

        return $conflicts;
    }
}
