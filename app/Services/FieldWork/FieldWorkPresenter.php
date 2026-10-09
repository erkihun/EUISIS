<?php

declare(strict_types=1);

namespace App\Services\FieldWork;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\FieldWorkDestinationType;
use App\Models\Employee;
use App\Models\FieldWorkHistory;
use App\Models\FieldWorkLocationEvent;
use App\Models\FieldWorkParticipant;
use App\Models\FieldWorkRequest;
use App\Models\User;

/**
 * Shapes field work for Inertia. Dates are local wall-clock ISO strings (the
 * calendar components localize them). Exact coordinates appear ONLY when
 * FieldWorkAccess grants precise location, and that disclosure is audited;
 * everyone else receives the verification status.
 */
class FieldWorkPresenter
{
    public const SUMMARY_WITH = [
        'requester:id,employee_number,full_name,name_en',
        'type:id,code,name_en,name_am',
        'destinationOrganization:id,name_en,name_am',
    ];

    public function __construct(
        private readonly FieldWorkSettings $settings,
        private readonly FieldWorkAccess $access,
        private readonly WriteAuditLogAction $audit,
    ) {}

    /** @return array<string, mixed> */
    public function summary(FieldWorkRequest $request): array
    {
        $snapshot = $request->context_snapshot ?? [];

        return [
            'id' => $request->id,
            'reference_number' => $request->reference_number,
            'status' => $request->status->value,
            'monitoring_flag' => $request->monitoringFlag()?->value,
            'supervisor_resolution' => $request->supervisor_resolution?->value,
            'type' => $request->type ? ['name_en' => $request->type->name_en, 'name_am' => $request->type->name_am] : null,
            'requester' => $this->employee($request->requester),
            'organization' => $snapshot['organization'] ?? null,
            'organization_unit' => $snapshot['organization_unit'] ?? null,
            'position' => $snapshot['position'] ?? null,
            'destination_type' => $request->destination_type->value,
            'destination' => $this->destinationLabel($request),
            'starts_at' => $this->settings->local($request->starts_at),
            'expected_return_at' => $this->settings->local($request->expected_return_at),
            'actual_return_at' => $this->settings->local($request->actual_return_at),
            'schedule_type' => $request->schedule_type->value,
            'is_team' => $request->is_team,
            'participants_count' => $request->participants_count ?? null,
            'submitted_at' => $this->settings->local($request->submitted_at),
        ];
    }

    /** @return array<string, mixed> */
    public function detail(FieldWorkRequest $request, User $viewer): array
    {
        $request->loadMissing([
            ...self::SUMMARY_WITH,
            'destinationOrganizationUnit:id,name_en,name_am',
            'participants.employee:id,employee_number,full_name,name_en',
            'participants.locationEvents',
            'histories.actor:id,name',
            'supervisor:id,name',
            'decider:id,name',
        ]);

        $precise = $this->access->canViewPreciseLocation($viewer, $request);
        $status = $precise || $this->access->canViewLocationStatus($viewer, $request);

        if ($precise && $request->participants->contains(fn (FieldWorkParticipant $p): bool => $p->locationEvents->isNotEmpty())) {
            $this->audit->execute(AuditEventType::FieldWorkPreciseLocationViewed, $viewer, $request, $request->organization_id);
        }

        return [
            ...$this->summary($request),
            'purpose' => $request->purpose,
            'activity_description' => $request->activity_description,
            'destination_details' => [
                'organization' => $request->destinationOrganization ? ['id' => $request->destinationOrganization->id, 'name_en' => $request->destinationOrganization->name_en, 'name_am' => $request->destinationOrganization->name_am] : null,
                'organization_unit' => $request->destinationOrganizationUnit ? ['id' => $request->destinationOrganizationUnit->id, 'name_en' => $request->destinationOrganizationUnit->name_en, 'name_am' => $request->destinationOrganizationUnit->name_am] : null,
                'external_organization_name' => $request->external_organization_name,
                'site_name' => $request->site_name,
                'address' => $request->destination_address,
                'contact_person' => $request->contact_person,
                'contact_phone' => $request->contact_phone,
                'has_geofence' => $request->hasGeofence(),
                // The planned point is part of the request the requester typed; only precise viewers and the requester see it.
                'expected_point' => ($precise || $this->access->isRequester($viewer, $request)) && $request->hasGeofence()
                    ? ['latitude' => $request->expected_latitude, 'longitude' => $request->expected_longitude, 'radius_m' => $request->geofence_radius_m]
                    : null,
            ],
            'supervisor' => $request->supervisor ? ['name' => $request->supervisor->name] : null,
            'decision' => $request->decided_at ? [
                'by' => $request->decider?->name,
                'at' => $this->settings->local($request->decided_at),
                'reason' => $request->decision_reason,
            ] : null,
            'completion' => $request->completed_at ? [
                'at' => $this->settings->local($request->completed_at),
                'actual_start_at' => $this->settings->local($request->actual_start_at),
                'actual_return_at' => $this->settings->local($request->actual_return_at),
                'note' => $request->completion_note,
                'outcome' => $request->outcome,
                'follow_up_required' => $request->follow_up_required,
                'follow_up_note' => $request->follow_up_note,
            ] : null,
            'cancel_reason' => $request->cancel_reason,
            'participants' => $request->participants->map(fn (FieldWorkParticipant $participant): array => [
                'id' => $participant->id,
                'role' => $participant->role->value,
                'employee' => $this->employee($participant->employee),
                'checked_in_at' => $this->settings->local($participant->checked_in_at),
                'checked_out_at' => $this->settings->local($participant->checked_out_at),
                'events' => $status ? $participant->locationEvents->map(fn (FieldWorkLocationEvent $event): array => $this->event($event, $precise))->values()->all() : [],
            ])->values()->all(),
            'history' => $request->histories->map(fn (FieldWorkHistory $history): array => [
                'action' => $history->action->value,
                'from_status' => $history->from_status,
                'to_status' => $history->to_status,
                'actor' => $history->actor?->name,
                'comment' => $history->comment,
                'at' => $this->settings->local($history->created_at),
            ])->values()->all(),
            'location_visibility' => $precise ? 'precise' : ($status ? 'status' : 'none'),
        ];
    }

    /** Values for re-opening the edit form, in the same shape the form submits. */
    public function formValues(FieldWorkRequest $request): array
    {
        $request->loadMissing(['participants.employee:id,employee_number,full_name,name_en', 'destinationOrganization:id,name_en,name_am', 'destinationOrganizationUnit:id,name_en,name_am']);
        $start = $request->starts_at->copy()->setTimezone($this->settings->timezone());
        $end = $request->expected_return_at->copy()->setTimezone($this->settings->timezone());

        return [
            'field_work_type_id' => $request->field_work_type_id,
            'purpose' => $request->purpose,
            'activity_description' => $request->activity_description ?? '',
            'destination_type' => $request->destination_type->value,
            'destination_organization_id' => $request->destination_organization_id ?? '',
            'destination_organization_unit_id' => $request->destination_organization_unit_id ?? '',
            'external_organization_name' => $request->external_organization_name ?? '',
            'site_name' => $request->site_name ?? '',
            'destination_address' => $request->destination_address ?? '',
            'contact_person' => $request->contact_person ?? '',
            'contact_phone' => $request->contact_phone ?? '',
            'expected_latitude' => $request->expected_latitude !== null ? (string) $request->expected_latitude : '',
            'expected_longitude' => $request->expected_longitude !== null ? (string) $request->expected_longitude : '',
            'geofence_radius_m' => $request->geofence_radius_m !== null ? (string) $request->geofence_radius_m : '',
            'start_date' => $start->toDateString(),
            'start_time' => $start->format('H:i'),
            'return_date' => $end->toDateString(),
            'return_time' => $end->format('H:i'),
            'participants' => $request->participants
                ->filter(fn (FieldWorkParticipant $p): bool => $p->employee_id !== $request->requester_employee_id)
                ->map(fn (FieldWorkParticipant $p): ?array => $this->employee($p->employee))->filter()->values()->all(),
            'destination_organization' => $request->destinationOrganization ? ['id' => $request->destinationOrganization->id, 'name_en' => $request->destinationOrganization->name_en, 'name_am' => $request->destinationOrganization->name_am] : null,
        ];
    }

    /** @return array{id: string, employee_number: ?string, full_name: ?string, name_en: ?string}|null */
    public function employee(?Employee $employee): ?array
    {
        return $employee === null ? null : [
            'id' => $employee->id,
            'employee_number' => $employee->employee_number,
            'full_name' => $employee->full_name,
            'name_en' => $employee->name_en,
        ];
    }

    /** @return array{name_en: ?string, name_am: ?string} */
    private function destinationLabel(FieldWorkRequest $request): array
    {
        return match ($request->destination_type) {
            FieldWorkDestinationType::RegisteredOrganization => ['name_en' => $request->destinationOrganization?->name_en, 'name_am' => $request->destinationOrganization?->name_am],
            FieldWorkDestinationType::ExternalOrganization => ['name_en' => $request->external_organization_name, 'name_am' => null],
            default => ['name_en' => $request->site_name ?? $request->destination_address, 'name_am' => null],
        };
    }

    /** @return array<string, mixed> */
    private function event(FieldWorkLocationEvent $event, bool $precise): array
    {
        return [
            'id' => $event->id,
            'event_type' => $event->event_type->value,
            'validation_status' => $event->validation_status->value,
            'review_state' => $event->review_state,
            'captured_at' => $this->settings->local($event->captured_at),
            'received_at' => $this->settings->local($event->received_at),
            ...($precise ? [
                'latitude' => $event->latitude,
                'longitude' => $event->longitude,
                'accuracy_m' => $event->accuracy_m,
                'distance_m' => $event->distance_m,
            ] : []),
        ];
    }
}
