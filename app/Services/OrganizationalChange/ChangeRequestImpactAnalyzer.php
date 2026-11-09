<?php

declare(strict_types=1);

namespace App\Services\OrganizationalChange;

use App\Enums\OrganizationalChangeEntityType;
use App\Models\EmployeeAssignment;
use App\Models\OrganizationalChangeRequest;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\PositionEstablishment;
use App\Models\UserOrganizationScope;

/**
 * Real impact figures for a proposed change.
 *
 * Every number here is a live count against master data. Nothing is estimated
 * or hard-coded: an empty result means the query genuinely returned nothing.
 * Run once before approval and again before implementation, because the
 * underlying data can move in between.
 */
final class ChangeRequestImpactAnalyzer
{
    /** @return array<string, mixed> */
    public function analyze(OrganizationalChangeRequest $request): array
    {
        $item = $request->primaryItem();

        if ($item === null) {
            return ['kind' => 'none', 'generated_at' => now()->toDateTimeString()];
        }

        $payload = match ($item->entity_type) {
            OrganizationalChangeEntityType::OrganizationUnit => $this->unitImpact($item->entity_id, $item->proposed_data ?? []),
            OrganizationalChangeEntityType::Position => $this->positionImpact($item->entity_id, $item->proposed_data ?? []),
            OrganizationalChangeEntityType::Narrative => ['kind' => 'narrative'],
        };

        return $payload + ['generated_at' => now()->toDateTimeString()];
    }

    /**
     * Structural impact of changing or removing a unit.
     *
     * @return array<string, mixed>
     */
    public function unitImpact(?string $unitId, array $proposed = []): array
    {
        if ($unitId === null) {
            // An "add unit" request affects nothing yet; the only meaningful
            // figure is the proposed parent's current child count.
            $parentId = $proposed['parent_unit_id'] ?? null;

            return [
                'kind' => 'organization_unit',
                'is_new_unit' => true,
                'parent_current_children' => $parentId
                    ? OrganizationUnit::query()->where('parent_unit_id', $parentId)->count()
                    : 0,
            ];
        }

        $subtreeIds = $this->unitSubtreeIds($unitId);

        return [
            'kind' => 'organization_unit',
            'is_new_unit' => false,
            'unit_id' => $unitId,
            'child_units_direct' => OrganizationUnit::query()->where('parent_unit_id', $unitId)->count(),
            'child_units_total' => max(0, count($subtreeIds) - 1),
            'positions_affected' => Position::query()->whereIn('organization_unit_id', $subtreeIds)->count(),
            'active_positions_affected' => Position::query()
                ->whereIn('organization_unit_id', $subtreeIds)
                ->where('is_active', true)
                ->count(),
            'employees_affected' => EmployeeAssignment::query()
                ->whereIn('organization_unit_id', $subtreeIds)
                ->where('is_current', true)
                ->distinct()
                ->count('employee_id'),
            'active_assignments' => EmployeeAssignment::query()
                ->whereIn('organization_unit_id', $subtreeIds)
                ->where('is_current', true)
                ->count(),
            'establishments_affected' => PositionEstablishment::query()
                ->whereIn('organization_unit_id', $subtreeIds)
                ->count(),
            'user_scopes_affected' => $this->userScopesForUnitOrganization($unitId),
        ];
    }

    /**
     * Occupancy and establishment impact of a position change.
     *
     * @return array<string, mixed>
     */
    public function positionImpact(?string $positionId, array $proposed = []): array
    {
        if ($positionId === null) {
            $unitId = $proposed['organization_unit_id'] ?? null;
            $requested = (int) ($proposed['quantity'] ?? 0);

            $establishmentRecords = $unitId
                ? PositionEstablishment::query()->where('organization_unit_id', $unitId)->count()
                : 0;
            $hasEstablishment = $establishmentRecords > 0;

            $approved = $hasEstablishment
                ? (int) PositionEstablishment::query()->where('organization_unit_id', $unitId)->sum('approved_slots')
                : null;
            $occupied = $unitId ? $this->occupiedCountForUnit($unitId) : 0;
            $existing = $unitId
                ? Position::query()->where('organization_unit_id', $unitId)->count()
                : 0;

            return [
                'kind' => 'position',
                'is_new_position' => true,
                'has_establishment_data' => $hasEstablishment,
                'establishment_records' => $establishmentRecords,
                'unit_existing_positions' => $existing,
                'unit_approved_positions' => $approved,
                'unit_occupied_positions' => $occupied,
                'unit_vacant_positions' => $hasEstablishment ? max(0, (int) $approved - $occupied) : null,
                'requested_additional_positions' => $requested,
                'expected_total_after_approval' => ($hasEstablishment ? (int) $approved : $existing) + $requested,
            ];
        }

        $position = Position::query()->find($positionId);

        if ($position === null) {
            return ['kind' => 'position', 'is_new_position' => false, 'position_missing' => true];
        }

        $establishmentRecords = PositionEstablishment::query()->where('position_id', $positionId)->count();
        $hasEstablishment = $establishmentRecords > 0;

        /*
         * Approved headcount comes from position_establishments. When an
         * organization has not adopted establishment records, that sum is
         * zero — which is NOT the same as "zero approved posts". Reporting it
         * as 0 produced a self-contradictory panel (0 approved, 3 occupied,
         * 0 vacant), so the establishment figures stay null and the UI says
         * the data is absent instead of inventing a ceiling.
         */
        $approved = $hasEstablishment
            ? (int) PositionEstablishment::query()->where('position_id', $positionId)->sum('approved_slots')
            : null;

        $occupied = EmployeeAssignment::query()
            ->where('position_id', $positionId)
            ->where('is_current', true)
            ->count();

        $additional = (int) ($proposed['additional_quantity'] ?? 0);

        /*
         * The always-meaningful baseline: how many position rows of this kind
         * exist right now. "Of this kind" is same title in the same unit and
         * organization, which is exactly what an increase clones.
         */
        $existing = Position::query()
            ->where('organization_id', $position->organization_id)
            ->where('title_en', $position->title_en)
            ->when(
                $position->organization_unit_id === null,
                fn ($query) => $query->whereNull('organization_unit_id'),
                fn ($query) => $query->where('organization_unit_id', $position->organization_unit_id),
            )
            ->count();

        return [
            'kind' => 'position',
            'is_new_position' => false,
            'position_id' => $positionId,
            'has_establishment_data' => $hasEstablishment,
            'establishment_records' => $establishmentRecords,
            'existing_positions' => $existing,
            'approved_positions' => $approved,
            'occupied_positions' => $occupied,
            'vacant_positions' => $hasEstablishment ? max(0, (int) $approved - $occupied) : null,
            'requested_additional_positions' => $additional,
            // Baseline follows whichever figure is real for this organization.
            'expected_total_after_approval' => ($hasEstablishment ? (int) $approved : $existing) + $additional,
            'assigned_employees' => EmployeeAssignment::query()
                ->where('position_id', $positionId)
                ->where('is_current', true)
                ->distinct()
                ->count('employee_id'),
            'active_assignments' => $occupied,
        ];
    }

    /**
     * Ids of a unit and every descendant, resolved iteratively so a corrupt
     * parent chain cannot recurse without bound.
     *
     * @return array<int, string>
     */
    public function unitSubtreeIds(string $unitId): array
    {
        $collected = [$unitId];
        $frontier = [$unitId];

        while ($frontier !== []) {
            $children = OrganizationUnit::query()
                ->whereIn('parent_unit_id', $frontier)
                ->pluck('id')
                ->map(static fn ($id): string => (string) $id)
                ->reject(static fn (string $id): bool => in_array($id, $collected, true))
                ->values()
                ->all();

            if ($children === []) {
                break;
            }

            $collected = array_merge($collected, $children);
            $frontier = $children;
        }

        return $collected;
    }

    private function occupiedCountForUnit(string $unitId): int
    {
        return EmployeeAssignment::query()
            ->where('organization_unit_id', $unitId)
            ->where('is_current', true)
            ->count();
    }

    /** User organization scopes pointing at the unit's organization. */
    private function userScopesForUnitOrganization(string $unitId): int
    {
        $organizationId = OrganizationUnit::query()->whereKey($unitId)->value('organization_id');

        if ($organizationId === null) {
            return 0;
        }

        return UserOrganizationScope::query()->where('organization_id', $organizationId)->count();
    }
}
