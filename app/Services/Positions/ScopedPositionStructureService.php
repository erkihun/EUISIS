<?php

declare(strict_types=1);

namespace App\Services\Positions;

use App\Models\EmployeeAssignment;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Support\Collection;

/**
 * Builds the Organization -> Organization Units -> Positions tree shown on the
 * Positions page, restricted to the organizations the actor may access.
 *
 * Runs a fixed three queries (organizations, units, positions) regardless of
 * tree size — the nesting is assembled in memory, so there is no N+1.
 */
readonly class ScopedPositionStructureService
{
    public function __construct(
        private OrganizationScopeService $organizationScopeService,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function build(User $user): array
    {
        $organizations = $this->scopedOrganizations($user);

        if ($organizations->isEmpty()) {
            return [];
        }

        $organizationIds = $organizations->pluck('id')->all();

        $units = OrganizationUnit::query()
            ->whereIn('organization_id', $organizationIds)
            ->whereNull('deleted_at')
            ->orderBy('sort_order')
            ->orderBy('name_en')
            ->get(['id', 'organization_id', 'parent_unit_id', 'code', 'name_en', 'name_am', 'status']);

        $positions = Position::query()
            ->whereIn('organization_id', $organizationIds)
            ->whereNull('deleted_at')
            ->orderBy('title_en')
            ->get([
                'id', 'organization_id', 'organization_unit_id', 'job_position_code',
                'old_code', 'title_en', 'title_am', 'bpr_name', 'is_active',
            ]);

        $occupiedPositionIds = $this->occupiedPositionIds($positions->pluck('id')->all());

        $positionsByUnit = $positions->groupBy(
            fn (Position $position): string => (string) ($position->organization_unit_id ?? '')
        );
        $unitsByParent = $units->groupBy(
            fn (OrganizationUnit $unit): string => (string) ($unit->parent_unit_id ?? '')
        );

        return $organizations->map(fn (Organization $organization): array => [
            'id' => $organization->id,
            'code' => $organization->code,
            'name_en' => $organization->name_en,
            'name_am' => $organization->name_am,
            'status' => $organization->status instanceof \BackedEnum
                ? $organization->status->value
                : (string) $organization->status,
            'units' => $this->buildUnits(
                $unitsByParent,
                $positionsByUnit,
                $occupiedPositionIds,
                $organization->id,
                null,
            ),
        ])->values()->all();
    }

    /**
     * Organizations the actor may see. An actor who is scoped but resolves to
     * an empty set gets an empty tree — never the full organization list.
     *
     * @return Collection<int, Organization>
     */
    private function scopedOrganizations(User $user): Collection
    {
        $query = Organization::query()->orderBy('name_en');

        if (! $this->organizationScopeService->isUnrestricted($user)) {
            $allowed = $this->organizationScopeService->allowedOrganizationIds($user);

            // Fail closed: an empty allow-list means "nothing", not "everything".
            if ($allowed === []) {
                return collect();
            }

            $query->whereIn('id', $allowed);
        }

        return $query->get(['id', 'code', 'name_en', 'name_am', 'status']);
    }

    /**
     * Position ids currently filled by an active assignment, so the tree can
     * badge vacant vs occupied without a per-position query.
     *
     * @param  array<int, string>  $positionIds
     * @return array<string, true>
     */
    private function occupiedPositionIds(array $positionIds): array
    {
        if ($positionIds === []) {
            return [];
        }

        return EmployeeAssignment::query()
            ->whereIn('position_id', $positionIds)
            ->where('is_current', true)
            ->pluck('position_id')
            ->flip()
            ->map(static fn (): bool => true)
            ->all();
    }

    /**
     * @param  Collection<string, Collection<int, OrganizationUnit>>  $unitsByParent
     * @param  Collection<string, Collection<int, Position>>  $positionsByUnit
     * @param  array<string, true>  $occupiedPositionIds
     * @return array<int, array<string, mixed>>
     */
    private function buildUnits(
        Collection $unitsByParent,
        Collection $positionsByUnit,
        array $occupiedPositionIds,
        string $organizationId,
        ?string $parentUnitId,
    ): array {
        $children = $unitsByParent->get((string) ($parentUnitId ?? ''), collect())
            ->where('organization_id', $organizationId);

        return $children->map(fn (OrganizationUnit $unit): array => [
            'id' => $unit->id,
            'code' => $unit->code,
            'name_en' => $unit->name_en,
            'name_am' => $unit->name_am,
            'parent_unit_id' => $unit->parent_unit_id,
            'status' => $unit->status instanceof \BackedEnum
                ? $unit->status->value
                : (string) $unit->status,
            'positions' => $positionsByUnit->get((string) $unit->id, collect())
                ->map(fn (Position $position): array => [
                    'id' => $position->id,
                    'code' => $position->job_position_code,
                    'old_code' => $position->old_code,
                    'standard_name' => $position->title_en,
                    'standard_name_am' => $position->title_am,
                    'bpr_name' => $position->bpr_name,
                    'organization_unit_id' => $position->organization_unit_id,
                    'status' => $position->is_active ? 'active' : 'inactive',
                    'occupancy_status' => isset($occupiedPositionIds[$position->id]) ? 'occupied' : 'vacant',
                ])->values()->all(),
            'children' => $this->buildUnits(
                $unitsByParent,
                $positionsByUnit,
                $occupiedPositionIds,
                $organizationId,
                $unit->id,
            ),
        ])->values()->all();
    }
}
