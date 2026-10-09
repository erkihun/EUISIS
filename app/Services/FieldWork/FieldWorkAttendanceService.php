<?php

declare(strict_types=1);

namespace App\Services\FieldWork;

use App\Enums\FieldWorkStatus;
use App\Models\FieldWorkParticipant;
use Illuminate\Support\Carbon;

/**
 * The attendance-facing read model of Field Work: "was this employee on
 * authorised official field work during this interval, and on which request?"
 *
 * EUISIS has no attendance module yet (docs/field-work-attendance-integration.md).
 * When one arrives it consumes these intervals to classify time as
 * OFFICIAL_FIELD_WORK instead of unexplained absence. This service:
 *
 *  - never writes attendance, clock-in / clock-out or biometric rows, and
 *    never alters raw device data — it only answers questions;
 *  - is interval-based: a partial-day field work covers its hours, not the
 *    whole day, so the attendance side can still expect office presence for
 *    the rest of it;
 *  - always returns the source reference (request id + reference number).
 *
 * The authorised window is [starts_at, expected_return_at], replaced by the
 * participant's own GPS check-in / check-out and the recorded actual return
 * where they exist. Nothing is fabricated: a missing check-out leaves the
 * planned return in place and reports checked_out_at = null.
 */
class FieldWorkAttendanceService
{
    public const ATTENDANCE_STATUS = 'OFFICIAL_FIELD_WORK';

    /**
     * @param  array<int, string>  $employeeIds
     * @return array<string, list<array{status: string, field_work_request_id: string, reference_number: string, starts_at: Carbon, ends_at: Carbon, checked_in_at: ?Carbon, checked_out_at: ?Carbon, request_status: string}>>
     */
    public function intervals(array $employeeIds, Carbon $from, Carbon $to): array
    {
        if ($employeeIds === []) {
            return [];
        }

        $result = [];

        FieldWorkParticipant::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereHas('request', fn ($query) => $query
                ->whereIn('status', FieldWorkStatus::authorisedValues())
                ->overlapping($from, $to))
            ->with('request:id,reference_number,status,starts_at,expected_return_at,actual_start_at,actual_return_at')
            ->get(['id', 'employee_id', 'field_work_request_id', 'checked_in_at', 'checked_out_at'])
            ->each(function (FieldWorkParticipant $participant) use (&$result): void {
                $request = $participant->request;
                $start = $participant->checked_in_at ?? $request->starts_at;
                $end = $participant->checked_out_at
                    ?? ($request->status === FieldWorkStatus::Completed ? $request->actual_return_at : null)
                    ?? $request->expected_return_at;

                $result[$participant->employee_id][] = [
                    'status' => self::ATTENDANCE_STATUS,
                    'field_work_request_id' => $request->id,
                    'reference_number' => $request->reference_number,
                    'starts_at' => $start->min($request->starts_at),
                    'ends_at' => $end,
                    'checked_in_at' => $participant->checked_in_at,
                    'checked_out_at' => $participant->checked_out_at,
                    'request_status' => $request->status->value,
                ];
            });

        return $result;
    }

    /** The covering interval at one instant, or null when the employee was not on field work. */
    public function at(string $employeeId, Carbon $instant): ?array
    {
        foreach ($this->intervals([$employeeId], $instant, $instant->copy()->addSecond())[$employeeId] ?? [] as $interval) {
            if ($interval['starts_at']->lte($instant) && $interval['ends_at']->gt($instant)) {
                return $interval;
            }
        }

        return null;
    }
}
