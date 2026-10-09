<?php

declare(strict_types=1);

namespace App\Services\FieldWork;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\FieldWorkLocationEventType;
use App\Enums\FieldWorkLocationValidationStatus;
use App\Enums\FieldWorkStatus;
use App\Models\FieldWorkLocationEvent;
use App\Models\FieldWorkParticipant;
use App\Models\FieldWorkRequest;
use App\Models\FieldWorkSession;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Validates and persists explicit GPS captures; it never schedules tracking. */
final class FieldWorkLocationService
{
    public function __construct(private readonly WriteAuditLogAction $audit, private readonly FieldWorkGpsPolicyService $policy, private readonly FieldWorkSessionService $sessions) {}

    /** @return array{event:FieldWorkLocationEvent,session:FieldWorkSession,blocked:bool,reason:?string} */
    public function capture(User $actor, FieldWorkRequest $request, FieldWorkLocationEventType $type, array $measurement): array
    {
        return DB::transaction(function () use ($actor, $request, $type, $measurement): array {
            $request = FieldWorkRequest::query()->with('participants')->lockForUpdate()->findOrFail($request->id);
            $employee = $actor->employee;
            if ($employee === null || $request->status !== FieldWorkStatus::Approved) {
                throw new AuthorizationException;
            }
            $participant = $request->participants->firstWhere('employee_id', $employee->id);
            if (! $participant instanceof FieldWorkParticipant) {
                throw new AuthorizationException;
            }
            $this->validateMeasurement($measurement);
            if ($type === FieldWorkLocationEventType::FieldCheckOut && ! $this->hasEvent($request, $participant, FieldWorkLocationEventType::FieldCheckIn)) {
                throw ValidationException::withMessages(['event_type' => 'Check-in is required before check-out.']);
            }
            $existing = FieldWorkLocationEvent::query()->where('idempotency_key', $measurement['idempotency_key'])->first();
            if ($existing !== null) {
                if ($existing->field_work_request_id !== $request->id
                    || $existing->field_work_participant_id !== $participant->id
                    || $existing->employee_id !== $employee->id
                    || $existing->event_type !== $type) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This idempotency key belongs to a different location operation.']);
                }

                $session = FieldWorkSession::query()->where('field_work_request_id', $request->id)->where('field_work_participant_id', $participant->id)->first();
                if ($session === null && $existing->event_type === FieldWorkLocationEventType::FieldCheckOut) {
                    $checkIn = FieldWorkLocationEvent::query()
                        ->where('field_work_request_id', $request->id)
                        ->where('field_work_participant_id', $participant->id)
                        ->where('event_type', FieldWorkLocationEventType::FieldCheckIn->value)
                        ->first();
                    if ($checkIn !== null) {
                        $this->sessions->record($request, $participant, $checkIn, $checkIn->review_state === 'blocked');
                    }
                }
                $session ??= $this->sessions->record($request, $participant, $existing, $existing->review_state === 'blocked');

                return ['event' => $existing, 'session' => $session, 'blocked' => $existing->review_state === 'blocked', 'reason' => $existing->review_state === 'blocked' ? 'The GPS observation was recorded but cannot satisfy the configured field-work policy.' : null];
            }
            if ($this->hasEvent($request, $participant, $type)) {
                throw ValidationException::withMessages(['event_type' => 'This location event has already been recorded.']);
            }
            $geofence = $this->geofence($request, (float) $measurement['latitude'], (float) $measurement['longitude']);
            $policy = $this->policy->snapshot();
            $decision = $this->policy->evaluate($policy, isset($measurement['accuracy_meters']) ? (float) $measurement['accuracy_meters'] : null, $geofence['status'] === FieldWorkLocationValidationStatus::OutsideExpectedArea);
            $event = new FieldWorkLocationEvent;
            $event->forceFill([
                'field_work_request_id' => $request->id, 'field_work_participant_id' => $participant->id, 'employee_id' => $employee->id, 'employee_assignment_id' => $participant->employee_assignment_id,
                'event_type' => $type, 'latitude' => $measurement['latitude'], 'longitude' => $measurement['longitude'], 'accuracy_meters' => $measurement['accuracy_meters'] ?? null,
                'altitude_meters' => $measurement['altitude_meters'] ?? null, 'heading_degrees' => $measurement['heading_degrees'] ?? null, 'speed_mps' => $measurement['speed_mps'] ?? null,
                'captured_at' => now(), 'received_at' => now(), 'location_source' => 'browser_geolocation', 'permission_state' => 'granted',
                'is_within_expected_area' => $geofence['within'], 'distance_from_destination_meters' => $geofence['distance'], 'validation_status' => $geofence['status'], 'validation_reason' => $geofence['reason'], 'policy_snapshot' => $policy, 'validation_flags' => $decision['flags'], 'review_state' => $decision['review_state'], 'idempotency_key' => $measurement['idempotency_key'],
            ]);
            $event->save();
            $session = $this->sessions->record($request, $participant, $event, $decision['blocked']);
            $this->audit->execute($type === FieldWorkLocationEventType::FieldCheckIn ? AuditEventType::FieldWorkCheckInRecorded : AuditEventType::FieldWorkCheckOutRecorded, $actor, $request, $request->organization_id, newValues: ['event_type' => $type->value, 'validation_status' => $event->validation_status->value, 'review_state' => $event->review_state, 'validation_flags' => $event->validation_flags]);

            return ['event' => $event, 'session' => $session, 'blocked' => $decision['blocked'], 'reason' => $decision['reason']];
        });
    }

    private function hasEvent(FieldWorkRequest $request, FieldWorkParticipant $participant, FieldWorkLocationEventType $type): bool
    {
        return FieldWorkLocationEvent::query()
            ->where('field_work_request_id', $request->id)
            ->where('field_work_participant_id', $participant->id)
            ->where('event_type', $type->value)
            ->where('review_state', '!=', 'blocked')
            ->exists();
    }

    private function validateMeasurement(array $value): void
    {
        $lat = filter_var($value['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
        $lng = filter_var($value['longitude'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($lat === false || $lat < -90 || $lat > 90 || $lng === false || $lng < -180 || $lng > 180) {
            throw ValidationException::withMessages(['location' => 'Invalid GPS coordinate.']);
        }
        foreach (['accuracy_meters', 'altitude_meters', 'speed_mps'] as $field) {
            if (isset($value[$field]) && (float) $value[$field] < 0) {
                throw ValidationException::withMessages([$field => 'GPS measurement cannot be negative.']);
            }
        }
        if (isset($value['heading_degrees']) && ((float) $value['heading_degrees'] < 0 || (float) $value['heading_degrees'] > 360)) {
            throw ValidationException::withMessages(['heading_degrees' => 'Heading must be between 0 and 360.']);
        }
    }

    /** @return array{within:?bool,distance:?float,status:FieldWorkLocationValidationStatus,reason:?string} */
    private function geofence(FieldWorkRequest $request, float $lat, float $lng): array
    {
        if ($request->destination_latitude === null || $request->destination_longitude === null || $request->destination_radius_meters === null) {
            return ['within' => null, 'distance' => null, 'status' => FieldWorkLocationValidationStatus::CannotValidate, 'reason' => 'Expected destination coordinates or radius are not configured.'];
        }
        $distance = $this->haversine($lat, $lng, (float) $request->destination_latitude, (float) $request->destination_longitude);
        $within = $distance <= $request->destination_radius_meters;

        return ['within' => $within, 'distance' => $distance, 'status' => $within ? FieldWorkLocationValidationStatus::WithinExpectedArea : FieldWorkLocationValidationStatus::OutsideExpectedArea, 'reason' => null];
    }

    private function haversine(float $fromLat, float $fromLng, float $toLat, float $toLng): float
    {
        $earthRadius = 6371008.8;
        $latDelta = deg2rad($toLat - $fromLat);
        $lngDelta = deg2rad($toLng - $fromLng);
        $a = sin($latDelta / 2) ** 2 + cos(deg2rad($fromLat)) * cos(deg2rad($toLat)) * sin($lngDelta / 2) ** 2;

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
