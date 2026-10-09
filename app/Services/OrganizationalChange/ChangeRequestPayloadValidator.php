<?php

declare(strict_types=1);

namespace App\Services\OrganizationalChange;

use App\Enums\OrganizationalChangeRequestType;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Type-specific validation for a proposed change.
 *
 * One lifecycle, many payload shapes: this class is the only place that knows
 * what each request type requires. It returns a normalised payload plus the
 * "before" snapshot, and it refuses anything that could not legally be applied
 * — a hierarchy cycle, a cross-organization move, an occupied position being
 * abolished.
 */
final readonly class ChangeRequestPayloadValidator
{
    public function __construct(
        private ChangeRequestScopeService $scope,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{proposed: array<string, mixed>, before: array<string, mixed>|null, entity_id: string|null}
     *
     * @throws ValidationException
     */
    public function validate(User $actor, OrganizationalChangeRequestType $type, string $organizationId, array $input): array
    {
        return match ($type) {
            OrganizationalChangeRequestType::AddOrganizationUnit => $this->addUnit($actor, $organizationId, $input),
            OrganizationalChangeRequestType::UpdateOrganizationUnit => $this->updateUnit($actor, $organizationId, $input),
            OrganizationalChangeRequestType::MoveOrganizationUnit,
            OrganizationalChangeRequestType::ChangeStructuralParent => $this->moveUnit($actor, $organizationId, $input),
            OrganizationalChangeRequestType::DeactivateOrganizationUnit => $this->deactivateUnit($actor, $organizationId, $input),
            OrganizationalChangeRequestType::ChangeFunctionalRelationship => $this->functionalRelationship($actor, $organizationId, $input),
            OrganizationalChangeRequestType::AddPosition => $this->addPosition($actor, $organizationId, $input),
            OrganizationalChangeRequestType::UpdatePosition => $this->updatePosition($actor, $organizationId, $input),
            OrganizationalChangeRequestType::MovePosition => $this->movePosition($actor, $organizationId, $input),
            OrganizationalChangeRequestType::IncreasePositions => $this->increasePositions($actor, $organizationId, $input),
            OrganizationalChangeRequestType::AbolishPosition => $this->abolishPosition($actor, $organizationId, $input),
            OrganizationalChangeRequestType::ChangePositionStatus => $this->changePositionStatus($actor, $organizationId, $input),
            OrganizationalChangeRequestType::ChangeGrade => $this->changeGrade($actor, $organizationId, $input),
            OrganizationalChangeRequestType::StructuralChange,
            OrganizationalChangeRequestType::OtherStructureRequest => $this->narrative($input),
        };
    }

    // ── Organization unit types ─────────────────────────────────────────────

    /** @return array{proposed: array<string, mixed>, before: null, entity_id: null} */
    private function addUnit(User $actor, string $organizationId, array $input): array
    {
        $this->require($input, ['name_en', 'organization_unit_type_id']);

        $parent = $this->scope->assertUnitInScope($actor, 'parent_unit_id', $this->str($input, 'parent_unit_id'), $organizationId);
        $this->assertParentUsable($parent, 'parent_unit_id');

        return [
            'proposed' => [
                'organization_id' => $organizationId,
                'parent_unit_id' => $parent?->getKey(),
                'organization_unit_type_id' => $this->str($input, 'organization_unit_type_id'),
                'unit_type' => $this->str($input, 'unit_type'),
                'name_en' => trim((string) $input['name_en']),
                'name_am' => $this->str($input, 'name_am'),
                'description_en' => $this->str($input, 'description_en'),
                'description_am' => $this->str($input, 'description_am'),
                'functional_parent_unit_id' => $this->scope
                    ->assertUnitInScope($actor, 'functional_parent_unit_id', $this->str($input, 'functional_parent_unit_id'), $organizationId)
                    ?->getKey(),
                'effective_from' => $this->date($input, 'effective_from'),
                'sort_order' => isset($input['sort_order']) ? (int) $input['sort_order'] : 0,
            ],
            'before' => null,
            'entity_id' => null,
        ];
    }

    private function updateUnit(User $actor, string $organizationId, array $input): array
    {
        $unit = $this->requireUnit($actor, $organizationId, $input);

        $proposed = array_filter([
            'name_en' => $this->str($input, 'name_en'),
            'name_am' => $this->str($input, 'name_am'),
            'description_en' => $this->str($input, 'description_en'),
            'description_am' => $this->str($input, 'description_am'),
            'organization_unit_type_id' => $this->str($input, 'organization_unit_type_id'),
            'unit_type' => $this->str($input, 'unit_type'),
            'effective_from' => $this->date($input, 'effective_from'),
        ], static fn ($value): bool => $value !== null);

        if ($proposed === []) {
            throw ValidationException::withMessages([
                'proposed_data' => __('organizational-change-requests.errors.no_changes_proposed'),
            ]);
        }

        return [
            'proposed' => $proposed,
            'before' => $this->unitSnapshot($unit),
            'entity_id' => (string) $unit->getKey(),
        ];
    }

    private function moveUnit(User $actor, string $organizationId, array $input): array
    {
        $unit = $this->requireUnit($actor, $organizationId, $input);
        $newParent = $this->scope->assertUnitInScope($actor, 'parent_unit_id', $this->str($input, 'parent_unit_id'), $organizationId);

        $this->assertParentUsable($newParent, 'parent_unit_id');

        if ($newParent !== null && (string) $newParent->getKey() === (string) $unit->getKey()) {
            throw ValidationException::withMessages([
                'parent_unit_id' => __('organizational-change-requests.errors.unit_own_parent'),
            ]);
        }

        if ($newParent !== null && $this->wouldCreateCycle($unit, $newParent)) {
            throw ValidationException::withMessages([
                'parent_unit_id' => __('organizational-change-requests.errors.hierarchy_cycle'),
            ]);
        }

        if ((string) ($unit->parent_unit_id ?? '') === (string) ($newParent?->getKey() ?? '')) {
            throw ValidationException::withMessages([
                'parent_unit_id' => __('organizational-change-requests.errors.no_changes_proposed'),
            ]);
        }

        return [
            'proposed' => [
                'parent_unit_id' => $newParent?->getKey(),
                'effective_from' => $this->date($input, 'effective_from'),
            ],
            'before' => $this->unitSnapshot($unit),
            'entity_id' => (string) $unit->getKey(),
        ];
    }

    private function deactivateUnit(User $actor, string $organizationId, array $input): array
    {
        $unit = $this->requireUnit($actor, $organizationId, $input);

        return [
            'proposed' => [
                'status' => 'inactive',
                'effective_to' => $this->date($input, 'effective_to'),
            ],
            'before' => $this->unitSnapshot($unit),
            'entity_id' => (string) $unit->getKey(),
        ];
    }

    private function functionalRelationship(User $actor, string $organizationId, array $input): array
    {
        $unit = $this->requireUnit($actor, $organizationId, $input);
        $functionalParent = $this->scope->assertUnitInScope(
            $actor,
            'functional_parent_unit_id',
            $this->str($input, 'functional_parent_unit_id'),
            $organizationId,
        );

        return [
            'proposed' => [
                'functional_parent_unit_id' => $functionalParent?->getKey(),
                'relationship_note' => $this->str($input, 'relationship_note'),
                'effective_from' => $this->date($input, 'effective_from'),
            ],
            'before' => $this->unitSnapshot($unit),
            'entity_id' => (string) $unit->getKey(),
        ];
    }

    // ── Position types ──────────────────────────────────────────────────────

    private function addPosition(User $actor, string $organizationId, array $input): array
    {
        $this->require($input, ['title_en']);

        $unit = $this->scope->assertUnitInScope($actor, 'organization_unit_id', $this->str($input, 'organization_unit_id'), $organizationId);
        $quantity = $this->quantity($input);

        return [
            'proposed' => [
                'organization_id' => $organizationId,
                'organization_unit_id' => $unit?->getKey(),
                'title_en' => trim((string) $input['title_en']),
                'title_am' => $this->str($input, 'title_am'),
                'occupation_id' => $this->str($input, 'occupation_id'),
                'grade_level' => $this->str($input, 'grade_level'),
                'job_family' => $this->str($input, 'job_family'),
                'description_en' => $this->str($input, 'description_en'),
                'description_am' => $this->str($input, 'description_am'),
                'quantity' => $quantity,
                'is_active' => (bool) ($input['is_active'] ?? true),
                'effective_from' => $this->date($input, 'effective_from'),
            ],
            'before' => null,
            'entity_id' => null,
        ];
    }

    private function updatePosition(User $actor, string $organizationId, array $input): array
    {
        $position = $this->requirePosition($actor, $organizationId, $input);

        $proposed = array_filter([
            'title_en' => $this->str($input, 'title_en'),
            'title_am' => $this->str($input, 'title_am'),
            'description_en' => $this->str($input, 'description_en'),
            'description_am' => $this->str($input, 'description_am'),
            'occupation_id' => $this->str($input, 'occupation_id'),
            'job_family' => $this->str($input, 'job_family'),
            'grade_level' => $this->str($input, 'grade_level'),
        ], static fn ($value): bool => $value !== null);

        if ($proposed === []) {
            throw ValidationException::withMessages([
                'proposed_data' => __('organizational-change-requests.errors.no_changes_proposed'),
            ]);
        }

        return [
            'proposed' => $proposed,
            'before' => $this->positionSnapshot($position),
            'entity_id' => (string) $position->getKey(),
        ];
    }

    private function movePosition(User $actor, string $organizationId, array $input): array
    {
        $position = $this->requirePosition($actor, $organizationId, $input);
        $targetUnit = $this->scope->assertUnitInScope($actor, 'organization_unit_id', $this->str($input, 'organization_unit_id'), $organizationId);

        if ($targetUnit === null) {
            throw ValidationException::withMessages([
                'organization_unit_id' => __('organizational-change-requests.errors.target_unit_required'),
            ]);
        }

        // A position may only move between units of the organization it already
        // belongs to. Cross-organization movement is a transfer, not a move.
        if ($targetUnit->organization_id !== $position->organization_id) {
            throw ValidationException::withMessages([
                'organization_unit_id' => __('organizational-change-requests.errors.illegal_cross_organization_move'),
            ]);
        }

        if ((string) $position->organization_unit_id === (string) $targetUnit->getKey()) {
            throw ValidationException::withMessages([
                'organization_unit_id' => __('organizational-change-requests.errors.no_changes_proposed'),
            ]);
        }

        return [
            'proposed' => [
                'organization_unit_id' => $targetUnit->getKey(),
                'effective_from' => $this->date($input, 'effective_from'),
                // Position codes stay stable across a move unless a separate
                // regeneration is authorised elsewhere.
                'preserve_code' => true,
            ],
            'before' => $this->positionSnapshot($position),
            'entity_id' => (string) $position->getKey(),
        ];
    }

    private function increasePositions(User $actor, string $organizationId, array $input): array
    {
        $position = $this->requirePosition($actor, $organizationId, $input);
        $additional = $this->quantity($input, 'additional_quantity');

        return [
            'proposed' => [
                'additional_quantity' => $additional,
                'effective_from' => $this->date($input, 'effective_from'),
            ],
            'before' => $this->positionSnapshot($position),
            'entity_id' => (string) $position->getKey(),
        ];
    }

    private function abolishPosition(User $actor, string $organizationId, array $input): array
    {
        $position = $this->requirePosition($actor, $organizationId, $input);

        // An occupied position cannot be abolished directly. The employee must
        // be reassigned or transferred first; the message says so explicitly.
        $occupied = $this->activeAssignmentCount($position);

        if ($occupied > 0) {
            throw ValidationException::withMessages([
                'entity_id' => __('organizational-change-requests.errors.occupied_position_cannot_be_abolished', [
                    'count' => $occupied,
                ]),
            ]);
        }

        return [
            'proposed' => [
                'is_active' => false,
                'abolish' => true,
                'effective_to' => $this->date($input, 'effective_to'),
            ],
            'before' => $this->positionSnapshot($position),
            'entity_id' => (string) $position->getKey(),
        ];
    }

    private function changePositionStatus(User $actor, string $organizationId, array $input): array
    {
        $position = $this->requirePosition($actor, $organizationId, $input);

        if (! array_key_exists('is_active', $input)) {
            throw ValidationException::withMessages([
                'is_active' => __('organizational-change-requests.errors.status_required'),
            ]);
        }

        $isActive = filter_var($input['is_active'], FILTER_VALIDATE_BOOL);

        if ($isActive === (bool) $position->is_active) {
            throw ValidationException::withMessages([
                'is_active' => __('organizational-change-requests.errors.no_changes_proposed'),
            ]);
        }

        if (! $isActive && $this->activeAssignmentCount($position) > 0) {
            throw ValidationException::withMessages([
                'is_active' => __('organizational-change-requests.errors.occupied_position_cannot_be_deactivated'),
            ]);
        }

        return [
            'proposed' => [
                'is_active' => $isActive,
                'effective_from' => $this->date($input, 'effective_from'),
            ],
            'before' => $this->positionSnapshot($position),
            'entity_id' => (string) $position->getKey(),
        ];
    }

    private function changeGrade(User $actor, string $organizationId, array $input): array
    {
        $position = $this->requirePosition($actor, $organizationId, $input);
        $grade = $this->str($input, 'grade_level');

        if ($grade === null) {
            throw ValidationException::withMessages([
                'grade_level' => __('organizational-change-requests.errors.grade_required'),
            ]);
        }

        if ($grade === (string) $position->grade_level) {
            throw ValidationException::withMessages([
                'grade_level' => __('organizational-change-requests.errors.no_changes_proposed'),
            ]);
        }

        return [
            'proposed' => [
                'grade_level' => $grade,
                'effective_from' => $this->date($input, 'effective_from'),
            ],
            'before' => $this->positionSnapshot($position),
            'entity_id' => (string) $position->getKey(),
        ];
    }

    /** A narrative request carries a description only; it has no machine-applicable payload. */
    private function narrative(array $input): array
    {
        $summary = $this->str($input, 'summary');

        if ($summary === null) {
            throw ValidationException::withMessages([
                'summary' => __('organizational-change-requests.errors.summary_required'),
            ]);
        }

        return [
            'proposed' => [
                'summary' => $summary,
                'details' => $this->str($input, 'details'),
                'effective_from' => $this->date($input, 'effective_from'),
            ],
            'before' => null,
            'entity_id' => null,
        ];
    }

    // ── Shared checks ───────────────────────────────────────────────────────

    /**
     * Walk up from the proposed parent. If we meet the unit being moved, the
     * move would make the unit its own ancestor.
     */
    public function wouldCreateCycle(OrganizationUnit $unit, OrganizationUnit $newParent): bool
    {
        $seen = [];
        $cursor = $newParent;

        while ($cursor !== null) {
            $cursorId = (string) $cursor->getKey();

            if ($cursorId === (string) $unit->getKey()) {
                return true;
            }

            // Defensive: a pre-existing cycle in the data must not hang the request.
            if (isset($seen[$cursorId])) {
                return true;
            }

            $seen[$cursorId] = true;

            if ($cursor->parent_unit_id === null) {
                return false;
            }

            $cursor = OrganizationUnit::query()->find($cursor->parent_unit_id);
        }

        return false;
    }

    /**
     * How many employees currently occupy this position.
     *
     * "Current" means the assignment is flagged as current and has not been
     * end-dated; both conditions are checked because historical rows keep
     * is_current false while open-ended rows keep effective_to null.
     */
    public function activeAssignmentCount(Position $position): int
    {
        return $position->assignments()
            ->where('is_current', true)
            ->where(function ($query): void {
                $query->whereNull('effective_to')->orWhere('effective_to', '>=', now()->toDateString());
            })
            ->count();
    }

    private function assertParentUsable(?OrganizationUnit $parent, string $field): void
    {
        if ($parent === null) {
            return;
        }

        if ($parent->trashed()) {
            throw ValidationException::withMessages([
                $field => __('organizational-change-requests.errors.parent_not_active'),
            ]);
        }

        $status = $parent->status instanceof \BackedEnum ? $parent->status->value : (string) $parent->status;

        if ($status !== '' && $status !== 'active') {
            throw ValidationException::withMessages([
                $field => __('organizational-change-requests.errors.parent_not_active'),
            ]);
        }
    }

    private function requireUnit(User $actor, string $organizationId, array $input): OrganizationUnit
    {
        $unit = $this->scope->assertUnitInScope($actor, 'entity_id', $this->str($input, 'entity_id'), $organizationId);

        if ($unit === null) {
            throw ValidationException::withMessages([
                'entity_id' => __('organizational-change-requests.errors.unit_required'),
            ]);
        }

        return $unit;
    }

    private function requirePosition(User $actor, string $organizationId, array $input): Position
    {
        $position = $this->scope->assertPositionInScope($actor, 'entity_id', $this->str($input, 'entity_id'), $organizationId);

        if ($position === null) {
            throw ValidationException::withMessages([
                'entity_id' => __('organizational-change-requests.errors.position_required'),
            ]);
        }

        return $position;
    }

    private function quantity(array $input, string $key = 'quantity'): int
    {
        $value = (int) ($input[$key] ?? 0);

        if ($value < 1 || $value > 500) {
            throw ValidationException::withMessages([
                $key => __('organizational-change-requests.errors.invalid_quantity'),
            ]);
        }

        return $value;
    }

    private function require(array $input, array $keys): void
    {
        foreach ($keys as $key) {
            if (! isset($input[$key]) || trim((string) $input[$key]) === '') {
                throw ValidationException::withMessages([
                    $key => __('organizational-change-requests.errors.field_required'),
                ]);
            }
        }
    }

    private function str(array $input, string $key): ?string
    {
        $value = $input[$key] ?? null;

        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function date(array $input, string $key): ?string
    {
        $value = $this->str($input, $key);

        if ($value === null) {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                $key => __('organizational-change-requests.errors.invalid_date'),
            ]);
        }
    }

    /** @return array<string, mixed> */
    public function unitSnapshot(OrganizationUnit $unit): array
    {
        return [
            'id' => (string) $unit->getKey(),
            'code' => $unit->code,
            'name_en' => $unit->name_en,
            'name_am' => $unit->name_am,
            'parent_unit_id' => $unit->parent_unit_id,
            'parent_name_en' => $unit->parent?->name_en,
            'organization_unit_type_id' => $unit->organization_unit_type_id,
            'unit_type' => $unit->unit_type,
            'status' => $unit->status instanceof \BackedEnum ? $unit->status->value : $unit->status,
            'effective_from' => $unit->effective_from?->toDateString(),
            'effective_to' => $unit->effective_to?->toDateString(),
        ];
    }

    /** @return array<string, mixed> */
    public function positionSnapshot(Position $position): array
    {
        return [
            'id' => (string) $position->getKey(),
            'code' => $position->code,
            'job_position_code' => $position->job_position_code,
            'title_en' => $position->title_en,
            'title_am' => $position->title_am,
            'organization_unit_id' => $position->organization_unit_id,
            'organization_unit_name_en' => $position->organizationUnit?->name_en,
            'occupation_id' => $position->occupation_id,
            'grade_level' => $position->grade_level,
            'job_family' => $position->job_family,
            'is_active' => (bool) $position->is_active,
            'effective_from' => $position->effective_from?->toDateString(),
            'effective_to' => $position->effective_to?->toDateString(),
        ];
    }
}
