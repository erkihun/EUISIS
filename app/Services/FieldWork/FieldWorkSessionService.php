<?php

declare(strict_types=1);

namespace App\Services\FieldWork;

use App\Enums\FieldWorkLocationEventType;
use App\Enums\FieldWorkSessionStatus;
use App\Models\FieldWorkLocationEvent;
use App\Models\FieldWorkParticipant;
use App\Models\FieldWorkRequest;
use App\Models\FieldWorkSession;
use Illuminate\Validation\ValidationException;

/** Creates one operational session per participant without inventing biometric evidence. */
final class FieldWorkSessionService
{
    public function record(
        FieldWorkRequest $request,
        FieldWorkParticipant $participant,
        FieldWorkLocationEvent $event,
        bool $blocked,
    ): FieldWorkSession {
        $session = FieldWorkSession::query()
            ->where('field_work_request_id', $request->id)
            ->where('field_work_participant_id', $participant->id)
            ->lockForUpdate()
            ->first();

        if ($session === null) {
            $session = new FieldWorkSession;
            $session->forceFill([
                'field_work_request_id' => $request->id,
                'field_work_participant_id' => $participant->id,
                'employee_id' => $participant->employee_id,
                'employee_assignment_id' => $participant->employee_assignment_id,
                'approved_start_at' => $request->starts_at,
                'approved_end_at' => $request->expected_return_at,
                'status' => FieldWorkSessionStatus::ApprovedNotCheckedIn,
            ]);
        }

        if ($blocked) {
            // A blocked retry must not erase a real, earlier check-in. Only a
            // participant with no session yet receives the blocked marker.
            if (! $session->exists) {
                $session->forceFill(['status' => FieldWorkSessionStatus::GpsBlocked])->save();
            }

            return $session;
        }

        if ($event->event_type === FieldWorkLocationEventType::FieldCheckIn) {
            if ($session->checked_in_at !== null) {
                throw ValidationException::withMessages(['event_type' => 'This participant has already checked in.']);
            }
            $session->forceFill([
                'checked_in_at' => $event->received_at,
                'attendance_from' => $event->received_at,
                'status' => FieldWorkSessionStatus::CheckedIn,
            ])->save();

            return $session;
        }

        if ($session->checked_in_at === null || $session->checked_out_at !== null) {
            throw ValidationException::withMessages(['event_type' => 'A single completed check-in is required before check-out.']);
        }
        $session->forceFill([
            'checked_out_at' => $event->received_at,
            'attendance_to' => $event->received_at,
            'status' => FieldWorkSessionStatus::CheckedOut,
        ])->save();

        return $session;
    }
}
