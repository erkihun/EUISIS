<?php

declare(strict_types=1);

namespace App\Services\Integration\Events;

use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\IdCard;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use App\Models\Position;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Turns committed-with-the-change master-data model events into minimised
 * integration events (EventCatalog). Runs inside the save's transaction
 * (HasIntegrationEvents), so the outbox row is atomic with the change.
 *
 * Only real changes produce events: a save that changes nothing, or changes
 * only fields partners never see (national ID, phone, address, …), records
 * nothing. Re-revoking an already revoked card changes nothing and so emits
 * nothing.
 */
final class MasterDataEventProjector
{
    private const ORGANIZATION_FIELDS = ['code', 'name_en', 'name_am', 'organization_type_id', 'effective_from', 'effective_to', 'merged_into_id'];

    private const UNIT_FIELDS = ['code', 'name_en', 'name_am', 'unit_type', 'organization_unit_type_id', 'effective_from', 'effective_to'];

    private const POSITION_FIELDS = ['organization_id', 'organization_unit_id', 'job_position_code', 'title_en', 'title_am', 'grade_level', 'occupation_id', 'effective_from', 'effective_to'];

    private const ASSIGNMENT_FIELDS = ['organization_id', 'organization_unit_id', 'position_id', 'assignment_status', 'effective_from', 'effective_to', 'job_description_id'];

    public function __construct(private readonly IntegrationEventRecorder $recorder) {}

    public function created(Model $model): void
    {
        match (true) {
            $model instanceof Organization => $this->recorder->record('organization.created', $model->getKey(), $this->organizationData($model), $model->getKey()),
            $model instanceof OrganizationUnit => $this->recorder->record('organization_unit.created', $model->getKey(), $this->unitData($model), $this->id($model->organization_id)),
            $model instanceof Position => $this->recorder->record('position.created', $model->getKey(), $this->positionData($model), $this->id($model->organization_id)),
            $model instanceof Employee => $this->recorder->record('employee.created', $model->getKey(), [
                'employee_uuid' => $model->getKey(), 'employee_number' => $model->employee_number, 'status' => $this->scalar($model->status),
            ], $this->employeeOrganization($model)),
            $model instanceof EmployeeAssignment => $this->recorder->record('assignment.created', $model->getKey(), $this->assignmentData($model), $this->id($model->organization_id), effectiveAt: $this->date($model->effective_from)),
            $model instanceof IdCard => $this->cardCreated($model),
            default => null,
        };
    }

    public function updated(Model $model): void
    {
        match (true) {
            $model instanceof Organization => $this->organizationUpdated($model),
            $model instanceof OrganizationUnit => $this->unitUpdated($model),
            $model instanceof Position => $this->positionUpdated($model),
            $model instanceof Employee => $this->employeeUpdated($model),
            $model instanceof EmployeeAssignment => $this->assignmentUpdated($model),
            $model instanceof IdCard => $this->cardUpdated($model),
            default => null,
        };
    }

    public function deleted(Model $model): void
    {
        // Soft deletes are archivals: a tombstone, never a hard-deleted history.
        match (true) {
            $model instanceof Organization => $this->recorder->record('organization.status_changed', $model->getKey(), [
                'organization_uuid' => $model->getKey(), 'status' => 'archived', 'previous_status' => $this->scalar($model->status), 'state' => 'archived',
            ], $model->getKey()),
            $model instanceof OrganizationUnit => $this->recorder->record('organization_unit.status_changed', $model->getKey(), [
                'unit_uuid' => $model->getKey(), 'organization_uuid' => $this->id($model->organization_id), 'status' => 'archived', 'previous_status' => $this->scalar($model->status), 'state' => 'archived',
            ], $this->id($model->organization_id)),
            $model instanceof Position => $this->recorder->record('position.archived', $model->getKey(), [
                'position_uuid' => $model->getKey(), 'organization_uuid' => $this->id($model->organization_id), 'state' => 'archived', 'reason_category' => 'archived',
            ], $this->id($model->organization_id)),
            default => null,
        };
    }

    public function restored(Model $model): void
    {
        match (true) {
            $model instanceof Organization => $this->recorder->record('organization.status_changed', $model->getKey(), [
                'organization_uuid' => $model->getKey(), 'status' => $this->scalar($model->status), 'previous_status' => 'archived', 'state' => $this->scalar($model->status),
            ], $model->getKey()),
            $model instanceof OrganizationUnit => $this->recorder->record('organization_unit.status_changed', $model->getKey(), [
                'unit_uuid' => $model->getKey(), 'organization_uuid' => $this->id($model->organization_id), 'status' => $this->scalar($model->status), 'previous_status' => 'archived', 'state' => $this->scalar($model->status),
            ], $this->id($model->organization_id)),
            $model instanceof Position => $this->recorder->record('position.status_changed', $model->getKey(), [
                'position_uuid' => $model->getKey(), 'organization_uuid' => $this->id($model->organization_id), 'is_active' => (bool) $model->is_active, 'state' => $model->is_active ? 'active' : 'inactive',
            ], $this->id($model->organization_id)),
            default => null,
        };
    }

    // ── Organization / unit / position ─────────────────────────────────

    private function organizationUpdated(Organization $organization): void
    {
        if ($organization->wasChanged('status')) {
            $status = $this->scalar($organization->status);
            $this->recorder->record('organization.status_changed', $organization->getKey(), [
                'organization_uuid' => $organization->getKey(), 'status' => $status,
                'previous_status' => $this->scalar($organization->getOriginal('status')), 'state' => $status,
            ], $organization->getKey());
        }

        $changed = $this->changed($organization, self::ORGANIZATION_FIELDS);
        if ($changed !== []) {
            $this->recorder->record('organization.updated', $organization->getKey(), [...$this->organizationData($organization), 'changed_fields' => $changed], $organization->getKey());
        }
    }

    private function unitUpdated(OrganizationUnit $unit): void
    {
        $organizationId = $this->id($unit->organization_id);

        if ($unit->wasChanged('parent_unit_id') || $unit->wasChanged('organization_id')) {
            $this->recorder->record('organization_unit.moved', $unit->getKey(), [
                'unit_uuid' => $unit->getKey(), 'organization_uuid' => $organizationId,
                'parent_unit_uuid' => $this->id($unit->parent_unit_id), 'previous_parent_unit_uuid' => $this->id($unit->getOriginal('parent_unit_id')),
            ], $organizationId, $this->id($unit->getOriginal('organization_id')));
        }

        if ($unit->wasChanged('status')) {
            $status = $this->scalar($unit->status);
            $this->recorder->record('organization_unit.status_changed', $unit->getKey(), [
                'unit_uuid' => $unit->getKey(), 'organization_uuid' => $organizationId, 'status' => $status,
                'previous_status' => $this->scalar($unit->getOriginal('status')), 'state' => $status,
            ], $organizationId);
        }

        $changed = $this->changed($unit, self::UNIT_FIELDS);
        if ($changed !== []) {
            $this->recorder->record('organization_unit.updated', $unit->getKey(), [...$this->unitData($unit), 'changed_fields' => $changed], $organizationId);
        }
    }

    private function positionUpdated(Position $position): void
    {
        $organizationId = $this->id($position->organization_id);

        if ($position->wasChanged('is_active')) {
            $this->recorder->record('position.status_changed', $position->getKey(), [
                'position_uuid' => $position->getKey(), 'organization_uuid' => $organizationId,
                'is_active' => (bool) $position->is_active, 'state' => $position->is_active ? 'active' : 'inactive',
            ], $organizationId);
        }

        $changed = $this->changed($position, self::POSITION_FIELDS);
        if ($changed !== []) {
            $this->recorder->record('position.updated', $position->getKey(), [...$this->positionData($position), 'changed_fields' => $changed], $organizationId, $this->id($position->getOriginal('organization_id')));
        }
    }

    // ── Employee / assignment ──────────────────────────────────────────

    private function employeeUpdated(Employee $employee): void
    {
        $organizationId = $this->employeeOrganization($employee);

        if ($employee->wasChanged('status')) {
            $status = $this->scalar($employee->status);
            $this->recorder->record('employee.status_changed', $employee->getKey(), [
                'employee_uuid' => $employee->getKey(), 'employee_number' => $employee->employee_number, 'status' => $status,
                'previous_status' => $this->scalar($employee->getOriginal('status')), 'state' => $status,
            ], $organizationId);
        }

        if ($employee->wasChanged('current_assignment_id') && $employee->current_assignment_id !== null) {
            $assignment = EmployeeAssignment::query()->find($employee->current_assignment_id);
            $previous = $this->id($employee->getOriginal('current_assignment_id'));
            $previousOrganization = $previous === null ? null : $this->id(EmployeeAssignment::query()->whereKey($previous)->value('organization_id'));
            $this->recorder->record('employee.assignment_changed', $employee->getKey(), [
                'employee_uuid' => $employee->getKey(), 'assignment_uuid' => $this->id($employee->current_assignment_id), 'previous_assignment_uuid' => $previous,
                'organization_uuid' => $this->id($assignment?->organization_id), 'unit_uuid' => $this->id($assignment?->organization_unit_id), 'position_uuid' => $this->id($assignment?->position_id),
            ], $this->id($assignment?->organization_id), $previousOrganization, effectiveAt: $this->date($assignment?->effective_from));
        }

        $categories = [];
        foreach (EventCatalog::EMPLOYEE_PUBLIC_FIELDS as $field => $category) {
            if ($employee->wasChanged($field)) {
                $categories[$category] = true;
            }
        }
        if ($categories !== []) {
            $this->recorder->record('employee.updated', $employee->getKey(), [
                'employee_uuid' => $employee->getKey(), 'employee_number' => $employee->employee_number,
                'status' => $this->scalar($employee->status), 'changed_fields' => array_keys($categories),
            ], $organizationId);
        }
    }

    private function assignmentUpdated(EmployeeAssignment $assignment): void
    {
        $organizationId = $this->id($assignment->organization_id);
        $closedNow = ($assignment->wasChanged('is_current') && ! $assignment->is_current && (bool) $assignment->getOriginal('is_current'))
            || ($assignment->wasChanged('assignment_status') && $this->scalar($assignment->assignment_status) === 'closed');

        if ($closedNow) {
            $this->recorder->record('assignment.closed', $assignment->getKey(), [
                'assignment_uuid' => $assignment->getKey(), 'employee_uuid' => $this->id($assignment->employee_id), 'organization_uuid' => $organizationId,
                'assignment_status' => $this->scalar($assignment->assignment_status), 'effective_to' => $this->date($assignment->effective_to), 'state' => 'closed',
            ], $organizationId, effectiveAt: $this->date($assignment->effective_to));

            return;
        }

        $changed = $this->changed($assignment, self::ASSIGNMENT_FIELDS);
        if ($changed !== []) {
            $this->recorder->record('assignment.updated', $assignment->getKey(), [...$this->assignmentData($assignment), 'changed_fields' => $changed], $organizationId, $this->id($assignment->getOriginal('organization_id')), effectiveAt: $this->date($assignment->effective_from));
        }
    }

    // ── ID card ────────────────────────────────────────────────────────

    private function cardCreated(IdCard $card): void
    {
        if ($card->public_card_uuid === null) {
            return;
        }

        $status = $this->scalar($card->status);
        if (in_array($status, ['issued', 'active'], true)) {
            $this->recorder->record('id_card.issued', $card->getKey(), $this->cardIssuedData($card), $this->cardOrganization($card));
        }
    }

    private function cardUpdated(IdCard $card): void
    {
        // A card is visible to partners only through its public UUID.
        if ($card->public_card_uuid === null) {
            return;
        }

        $organizationId = $this->cardOrganization($card);
        $status = $this->scalar($card->status);
        $base = ['card_uuid' => $card->public_card_uuid, 'employee_uuid' => $this->id($card->employee_id), 'status' => $status, 'is_current' => (bool) $card->is_current];

        $revokedNow = ($card->wasChanged('status') && $status === 'revoked')
            || ($card->wasChanged('qr_status') && $card->qr_status !== 'active' && $card->getOriginal('qr_status') === 'active')
            || ($card->wasChanged('revoked_at') && $card->revoked_at !== null && $card->getOriginal('revoked_at') === null);

        if ($revokedNow) {
            $this->recorder->record('id_card.revoked', $card->getKey(), [
                ...$base, 'revoked_at' => ($card->revoked_at ?? Carbon::now())->toIso8601String(), 'state' => 'revoked',
            ], $organizationId);

            return;
        }

        if ($card->wasChanged('status')) {
            if (in_array($status, ['issued', 'active'], true) && ! in_array($this->scalar($card->getOriginal('status')), ['issued', 'active'], true)) {
                $this->recorder->record('id_card.issued', $card->getKey(), $this->cardIssuedData($card), $organizationId);
            } else {
                $this->recorder->record('id_card.status_changed', $card->getKey(), [
                    ...$base, 'previous_status' => $this->scalar($card->getOriginal('status')), 'state' => $status,
                ], $organizationId);
            }

            return;
        }

        if ($card->wasChanged('printed_at') && $card->getOriginal('printed_at') !== null && $card->printed_at !== null) {
            // Same card record printed again: the card_uuid — the identity — does not change.
            $this->recorder->record('id_card.reprinted', $card->getKey(), [...$base, 'printed_at' => $card->printed_at->toIso8601String()], $organizationId);
        }
    }

    // ── Payload builders (whitelists) ──────────────────────────────────

    /** @return array<string, mixed> */
    private function organizationData(Organization $organization): array
    {
        return ['organization_uuid' => $organization->getKey(), 'code' => $organization->code, 'name_en' => $organization->name_en, 'name_am' => $organization->name_am, 'status' => $this->scalar($organization->status)];
    }

    /** @return array<string, mixed> */
    private function unitData(OrganizationUnit $unit): array
    {
        return [
            'unit_uuid' => $unit->getKey(), 'organization_uuid' => $this->id($unit->organization_id), 'parent_unit_uuid' => $this->id($unit->parent_unit_id),
            'code' => $unit->code, 'name_en' => $unit->name_en, 'name_am' => $unit->name_am, 'status' => $this->scalar($unit->status),
        ];
    }

    /** @return array<string, mixed> */
    private function positionData(Position $position): array
    {
        return [
            'position_uuid' => $position->getKey(), 'organization_uuid' => $this->id($position->organization_id), 'unit_uuid' => $this->id($position->organization_unit_id),
            'code' => $position->job_position_code, 'title_en' => $position->title_en, 'title_am' => $position->title_am, 'is_active' => (bool) $position->is_active,
        ];
    }

    /** @return array<string, mixed> */
    private function assignmentData(EmployeeAssignment $assignment): array
    {
        return [
            'assignment_uuid' => $assignment->getKey(), 'employee_uuid' => $this->id($assignment->employee_id), 'organization_uuid' => $this->id($assignment->organization_id),
            'unit_uuid' => $this->id($assignment->organization_unit_id), 'position_uuid' => $this->id($assignment->position_id),
            'assignment_status' => $this->scalar($assignment->assignment_status), 'effective_from' => $this->date($assignment->effective_from),
            'effective_to' => $this->date($assignment->effective_to), 'is_current' => (bool) $assignment->is_current,
        ];
    }

    /** @return array<string, mixed> */
    private function cardIssuedData(IdCard $card): array
    {
        return [
            'card_uuid' => $card->public_card_uuid, 'employee_uuid' => $this->id($card->employee_id), 'status' => $this->scalar($card->status),
            'is_current' => (bool) $card->is_current, 'issued_at' => $card->issued_at?->toIso8601String(), 'expires_at' => $this->date($card->expires_at),
        ];
    }

    // ── Helpers ────────────────────────────────────────────────────────

    /** @param list<string> $fields @return list<string> */
    private function changed(Model $model, array $fields): array
    {
        return array_values(array_filter($fields, fn (string $field): bool => $model->wasChanged($field)));
    }

    private function employeeOrganization(Employee $employee): ?string
    {
        if ($employee->current_assignment_id === null) {
            return null;
        }

        return $this->id(EmployeeAssignment::query()->whereKey($employee->current_assignment_id)->value('organization_id'));
    }

    private function cardOrganization(IdCard $card): ?string
    {
        $assignmentId = Employee::query()->whereKey($card->employee_id)->value('current_assignment_id');

        return $assignmentId === null ? null : $this->id(EmployeeAssignment::query()->whereKey($assignmentId)->value('organization_id'));
    }

    private function id(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }

    private function scalar(mixed $value): ?string
    {
        return $value instanceof BackedEnum ? (string) $value->value : ($value === null ? null : (string) $value);
    }

    private function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse($value)->toDateString();
    }
}
