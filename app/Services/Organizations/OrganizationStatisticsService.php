<?php

declare(strict_types=1);

namespace App\Services\Organizations;

use App\Enums\AssignmentStatus;
use App\Enums\CardStatus;
use App\Enums\OrganizationUnitStatus;
use App\Models\EmployeeAssignment;
use App\Models\IdCard;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\UserOrganizationScope;
use Illuminate\Support\Facades\DB;

/**
 * Aggregate counters for the organization detail page.
 *
 * Every figure is produced by a grouped COUNT rather than by loading records,
 * so the cost does not grow with the size of the organization. Nothing here
 * returns employee-identifying data — only totals.
 */
readonly class OrganizationStatisticsService
{
    /**
     * @return array<string, mixed>
     */
    public function forOrganization(Organization $organization): array
    {
        $organizationId = $organization->getKey();

        return [
            'units' => $this->unitCounts($organizationId),
            'positions' => $this->positionCounts($organizationId),
            'employees' => $this->employeeCounts($organizationId),
            'id_cards' => $this->idCardCounts($organizationId),
            'child_organizations' => $this->childOrganizationCount($organizationId),
            'scoped_users' => $this->scopedUserCount($organizationId),
            'employees_by_unit' => $this->employeesByUnit($organizationId),
        ];
    }

    /** @return array<string, int> */
    private function unitCounts(string $organizationId): array
    {
        $byStatus = OrganizationUnit::query()
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->groupBy('status')
            ->selectRaw('status, count(*) as aggregate')
            ->pluck('aggregate', 'status')
            ->all();

        $normalized = $this->normalizeKeys($byStatus);

        return [
            'total' => array_sum($normalized),
            'active' => $normalized[OrganizationUnitStatus::Active->value] ?? 0,
            'inactive' => $normalized[OrganizationUnitStatus::Inactive->value] ?? 0,
            'draft' => $normalized[OrganizationUnitStatus::Draft->value] ?? 0,
            'archived' => $normalized[OrganizationUnitStatus::Archived->value] ?? 0,
        ];
    }

    /** @return array<string, int> */
    private function positionCounts(string $organizationId): array
    {
        $total = Position::query()
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->count();

        $active = Position::query()
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->count();

        // A position is occupied when a current, active assignment holds it.
        $occupied = Position::query()
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->whereHas('assignments', fn ($query) => $query
                ->where('is_current', true)
                ->where('assignment_status', AssignmentStatus::Active))
            ->count();

        return [
            'total' => $total,
            'active' => $active,
            'inactive' => $total - $active,
            'occupied' => $occupied,
            'vacant' => max($total - $occupied, 0),
            // Occupancy split, shaped for the status-distribution chart.
            'by_status' => [
                'occupied' => $occupied,
                'vacant' => max($total - $occupied, 0),
            ],
        ];
    }

    /** @return array<string, int> */
    private function employeeCounts(string $organizationId): array
    {
        $byStatus = EmployeeAssignment::query()
            ->join('employees', 'employees.id', '=', 'employee_assignments.employee_id')
            ->where('employee_assignments.organization_id', $organizationId)
            ->where('employee_assignments.is_current', true)
            ->groupBy('employees.status')
            ->selectRaw('employees.status as status, count(*) as aggregate')
            ->pluck('aggregate', 'status')
            ->all();

        $normalized = $this->normalizeKeys($byStatus);

        return [
            'total' => array_sum($normalized),
            'active' => $normalized['active'] ?? 0,
            'suspended' => $normalized['suspended'] ?? 0,
            'retired' => $normalized['retired'] ?? 0,
            'terminated' => $normalized['terminated'] ?? 0,
            'by_status' => $normalized,
        ];
    }

    /** @return array<string, int> */
    private function idCardCounts(string $organizationId): array
    {
        $byStatus = IdCard::query()
            ->join('employee_assignments', function ($join) use ($organizationId): void {
                $join->on('employee_assignments.employee_id', '=', 'id_cards.employee_id')
                    ->where('employee_assignments.is_current', true)
                    ->where('employee_assignments.organization_id', '=', $organizationId);
            })
            ->groupBy('id_cards.status')
            ->selectRaw('id_cards.status as status, count(*) as aggregate')
            ->pluck('aggregate', 'status')
            ->all();

        $normalized = $this->normalizeKeys($byStatus);

        $pending = ($normalized[CardStatus::PendingPrint->value] ?? 0)
            + ($normalized[CardStatus::Printed->value] ?? 0);

        $inactive = ($normalized[CardStatus::Expired->value] ?? 0)
            + ($normalized[CardStatus::Revoked->value] ?? 0)
            + ($normalized[CardStatus::Lost->value] ?? 0)
            + ($normalized[CardStatus::Damaged->value] ?? 0)
            + ($normalized[CardStatus::Replaced->value] ?? 0);

        return [
            'total' => array_sum($normalized),
            'active' => ($normalized[CardStatus::Active->value] ?? 0) + ($normalized[CardStatus::Issued->value] ?? 0),
            'pending' => $pending,
            'inactive' => $inactive,
            'by_status' => $normalized,
        ];
    }

    private function childOrganizationCount(string $organizationId): int
    {
        return DB::table('organization_edges')
            ->where('parent_organization_id', $organizationId)
            ->distinct()
            ->count('child_organization_id');
    }

    private function scopedUserCount(string $organizationId): int
    {
        return UserOrganizationScope::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->distinct()
            ->count('user_id');
    }

    /**
     * Head-count per unit for the breakdown table. Names come from the unit
     * itself; no employee records are exposed.
     *
     * @return array<int, array<string, mixed>>
     */
    private function employeesByUnit(string $organizationId): array
    {
        return EmployeeAssignment::query()
            ->join('organization_units', 'organization_units.id', '=', 'employee_assignments.organization_unit_id')
            ->where('employee_assignments.organization_id', $organizationId)
            ->where('employee_assignments.is_current', true)
            ->whereNull('organization_units.deleted_at')
            ->groupBy('organization_units.id', 'organization_units.code', 'organization_units.name_en', 'organization_units.name_am')
            ->orderBy('organization_units.name_en')
            ->get([
                'organization_units.id',
                'organization_units.code',
                'organization_units.name_en',
                'organization_units.name_am',
                DB::raw('count(*) as employees_count'),
            ])
            ->map(fn ($row): array => [
                'id' => $row->id,
                'code' => $row->code,
                'name_en' => $row->name_en,
                'name_am' => $row->name_am,
                'employees_count' => (int) $row->employees_count,
            ])
            ->all();
    }

    /**
     * Enum-backed columns come back as enum instances on some drivers and raw
     * strings on others; normalize to plain string keys.
     *
     * @param  array<mixed, mixed>  $counts
     * @return array<string, int>
     */
    private function normalizeKeys(array $counts): array
    {
        $normalized = [];

        foreach ($counts as $key => $count) {
            $stringKey = $key instanceof \BackedEnum ? (string) $key->value : (string) $key;
            $normalized[$stringKey] = ($normalized[$stringKey] ?? 0) + (int) $count;
        }

        return $normalized;
    }
}
