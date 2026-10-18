<?php

declare(strict_types=1);

namespace App\Services\Communication;

use App\Enums\HierarchyVersionStatus;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\HierarchyVersion;
use App\Models\OrganizationClosurePath;
use App\Models\OrganizationUnit;
use App\Models\User;

/**
 * Where a person sits, read from the configurable hierarchy: the published
 * hierarchy version's closure table for organizations, parent links for units.
 * No level name (Bureau, Sub-city, Woreda) appears anywhere.
 */
final class CommunicationHierarchy
{
    /** @var array<string, mixed> */
    private array $cache = [];

    public function publishedVersionId(): ?string
    {
        return $this->cache['version'] ??= HierarchyVersion::query()->where('status', HierarchyVersionStatus::Published)
            ->latest('effective_from')->latest('created_at')->value('id');
    }

    /** The person's current active assignment, or null (no employee record, not placed). */
    public function assignmentOf(User $user): ?EmployeeAssignment
    {
        if ($user->employee_id === null) {
            return null;
        }

        return $this->cache["assignment.{$user->getKey()}"] ??= EmployeeAssignment::query()
            ->whereKey(Employee::query()->whereKey($user->employee_id)->value('current_assignment_id'))
            ->where('assignment_status', 'active')
            ->first();
    }

    /** @return list<string> the organization and every organization above it */
    public function organizationAncestors(string $organizationId): array
    {
        return $this->cache["org-up.{$organizationId}"] ??= $this->closure('descendant_organization_id', $organizationId, 'ancestor_organization_id');
    }

    /** @return list<string> the organization and every organization under it */
    public function organizationDescendants(string $organizationId): array
    {
        return $this->cache["org-down.{$organizationId}"] ??= $this->closure('ancestor_organization_id', $organizationId, 'descendant_organization_id');
    }

    /** @return list<string> the unit and every unit above it, inside its organization */
    public function unitAncestors(string $unitId): array
    {
        if (isset($this->cache["unit-up.{$unitId}"])) {
            return $this->cache["unit-up.{$unitId}"];
        }
        $ids = [];
        $current = $unitId;
        while ($current !== null && ! in_array($current, $ids, true) && count($ids) < 50) {
            $ids[] = $current;
            $current = OrganizationUnit::query()->whereKey($current)->value('parent_unit_id');
        }

        return $this->cache["unit-up.{$unitId}"] = $ids;
    }

    /** @return list<string> the unit and every unit under it (breadth-first, one query per level) */
    public function unitDescendants(string $unitId): array
    {
        if (isset($this->cache["unit-down.{$unitId}"])) {
            return $this->cache["unit-down.{$unitId}"];
        }
        $all = [$unitId];
        $frontier = [$unitId];
        for ($depth = 0; $frontier !== [] && $depth < 50; $depth++) {
            $frontier = array_values(array_diff(
                OrganizationUnit::query()->whereIn('parent_unit_id', $frontier)->pluck('id')->map(fn ($id) => (string) $id)->all(),
                $all,
            ));
            array_push($all, ...$frontier);
        }

        return $this->cache["unit-down.{$unitId}"] = $all;
    }

    public function forget(): void
    {
        $this->cache = [];
    }

    /** @return list<string> */
    private function closure(string $match, string $id, string $pluck): array
    {
        $version = $this->publishedVersionId();
        if ($version === null) {
            return [$id];
        }
        $ids = OrganizationClosurePath::query()->where('hierarchy_version_id', $version)->where($match, $id)
            ->pluck($pluck)->map(fn ($v) => (string) $v)->all();

        return array_values(array_unique([$id, ...$ids]));
    }
}
