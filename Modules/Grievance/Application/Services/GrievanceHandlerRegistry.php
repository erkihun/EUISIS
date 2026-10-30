<?php

declare(strict_types=1);

namespace App\Services\Grievances;

use App\Enums\AssignmentStatus;
use App\Enums\CommitteeType;
use App\Enums\Grievance\GrievanceHandlerType;
use App\Enums\HierarchyVersionStatus;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\GrievanceCommittee;
use App\Models\GrievanceCommitteeMember;
use App\Models\GrievanceExternalAuthority;
use App\Models\HierarchyVersion;
use App\Models\Organization;
use App\Models\OrganizationClosurePath;
use App\Models\OrganizationUnit;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * One place that knows what a grievance handler is (docs/grievance-management.md §5):
 *
 *   committee           a GrievanceCommittee of selected employees
 *   organization_unit   a permanent Team/Directorate — an existing
 *                       OrganizationUnit, never a duplicate master table
 *   external_authority  e.g. the Administrative Tribunal
 *
 * Staff of a unit handler come from HR: employees whose current assignment is
 * in that unit or a unit beneath it. Nothing here widens organization scope.
 */
final class GrievanceHandlerRegistry
{
    /** @var array<string, list<string>> */
    private array $ancestorCache = [];

    public function __construct(private readonly GrievanceSettings $settings) {}

    public function find(GrievanceHandlerType $type, ?string $id): ?Model
    {
        if ($id === null) {
            return null;
        }

        return match ($type) {
            GrievanceHandlerType::Committee => GrievanceCommittee::query()->find($id),
            GrievanceHandlerType::OrganizationUnit => OrganizationUnit::query()->find($id),
            GrievanceHandlerType::ExternalAuthority => GrievanceExternalAuthority::query()->find($id),
            GrievanceHandlerType::Organization => Organization::query()->find($id),
        };
    }

    /**
     * Display data for a handler; safe to send to the complainant.
     *
     * @return array{type: string, id: string|null, name_en: string, name_am: string|null, organization_id: string|null, organization_name_en: string|null, organization_name_am: string|null}
     */
    public function describe(GrievanceHandlerType|string|null $type, ?string $id): array
    {
        $type = is_string($type) ? GrievanceHandlerType::tryFrom($type) : $type;
        $model = $type === null ? null : $this->find($type, $id);
        $organization = $model === null ? null : $this->organizationModelOf($type, $model);

        return [
            'type' => $type?->value ?? '',
            'id' => $id,
            'name_en' => (string) ($model?->getAttribute('name_en') ?? ''),
            'name_am' => $model?->getAttribute('name_am'),
            'organization_id' => $organization?->getKey(),
            'organization_name_en' => $organization?->name_en,
            'organization_name_am' => $organization?->name_am,
        ];
    }

    public function organizationIdOf(GrievanceHandlerType $type, ?string $id): ?string
    {
        $model = $this->find($type, $id);

        return $model === null ? null : $this->organizationModelOf($type, $model)?->getKey();
    }

    /**
     * A handler can receive a case: it exists, is active, and — for a
     * grievance committee — is approved and properly composed.
     *
     * @return list<string> problems (translation keys); empty when available
     */
    public function availabilityProblems(GrievanceHandlerType $type, ?string $id): array
    {
        $model = $this->find($type, $id);
        if ($model === null) {
            return ['grievances.errors.handler_missing'];
        }

        return match ($type) {
            GrievanceHandlerType::Committee => $this->committeeProblems($model),
            GrievanceHandlerType::OrganizationUnit => ($model->status?->value ?? $model->status) === 'active' ? [] : ['grievances.errors.handler_inactive'],
            GrievanceHandlerType::ExternalAuthority => $model->is_active ? [] : ['grievances.errors.handler_inactive'],
            GrievanceHandlerType::Organization => ['grievances.errors.handler_invalid'],
        };
    }

    /** @return list<string> */
    public function committeeProblems(GrievanceCommittee $committee): array
    {
        $problems = [];
        if (! $committee->isActive() || $committee->approved_at === null && $committee->created_by !== null) {
            $problems[] = 'grievances.errors.committee_not_active';
        }

        $members = $committee->members()->servingOn(now())->get();
        $count = $members->count();
        if ($count < $this->settings->committeeMinMembers()) {
            $problems[] = 'grievances.errors.committee_too_small';
        }
        if ($count > $this->settings->committeeMaxMembers()) {
            $problems[] = 'grievances.errors.committee_too_large';
        }
        if ($members->filter(fn (GrievanceCommitteeMember $m) => $m->roleEnum()->value === 'chairperson')->count() !== 1) {
            $problems[] = 'grievances.errors.committee_needs_one_chair';
        }
        if ($this->settings->committeeRequiresWriter() && $members->filter(fn (GrievanceCommitteeMember $m) => $m->roleEnum()->value === 'writer')->count() !== 1) {
            $problems[] = 'grievances.errors.committee_needs_one_writer';
        }

        return $problems;
    }

    /**
     * Grievance committees are the only committee type the grievance module
     * routes to; EPMS panels are excluded.
     *
     * @return list<string>
     */
    public static function grievanceCommitteeTypes(): array
    {
        return [CommitteeType::Grievance->value, CommitteeType::Tribunal->value];
    }

    /**
     * The employee's current placement: organization and unit (plus its
     * ancestor units, so a Team inside a Directorate belongs to both).
     *
     * @return array{organization_id: string|null, unit_ids: list<string>, position_id: string|null, assignment_id: string|null}
     */
    public function placementOf(?Employee $employee): array
    {
        $empty = ['organization_id' => null, 'unit_ids' => [], 'position_id' => null, 'assignment_id' => null];
        if ($employee === null) {
            return $empty;
        }

        $assignment = $this->currentAssignment($employee);
        if ($assignment === null) {
            return $empty;
        }

        return [
            'organization_id' => $assignment->organization_id,
            'unit_ids' => $assignment->organization_unit_id ? $this->unitWithAncestors($assignment->organization_unit_id) : [],
            'position_id' => $assignment->position_id,
            'assignment_id' => $assignment->getKey(),
        ];
    }

    public function currentAssignment(Employee $employee): ?EmployeeAssignment
    {
        $pointer = $employee->current_assignment_id
            ? EmployeeAssignment::query()->whereKey($employee->current_assignment_id)->first()
            : null;
        if ($pointer !== null && $pointer->is_current && $pointer->assignment_status === AssignmentStatus::Active) {
            return $pointer;
        }

        return EmployeeAssignment::query()
            ->where('employee_id', $employee->getKey())
            ->where('is_current', true)
            ->where('assignment_status', AssignmentStatus::Active->value)
            ->latest('effective_from')
            ->first();
    }

    /** @return list<string> the unit and every unit above it */
    public function unitWithAncestors(string $unitId): array
    {
        if (isset($this->ancestorCache[$unitId])) {
            return $this->ancestorCache[$unitId];
        }

        $ids = [];
        $cursor = $unitId;
        for ($depth = 0; $cursor !== null && $depth < 20 && ! in_array($cursor, $ids, true); $depth++) {
            $ids[] = $cursor;
            $parent = OrganizationUnit::query()->whereKey($cursor)->value('parent_unit_id');
            $cursor = is_string($parent) ? $parent : null;
        }

        return $this->ancestorCache[$unitId] = $ids;
    }

    /**
     * Ancestors of an organization in the published hierarchy (nearest
     * first), including itself — used for "applies to descendants" routes.
     *
     * @return list<string>
     */
    public function organizationWithAncestors(string $organizationId): array
    {
        $versionId = HierarchyVersion::query()->where('status', HierarchyVersionStatus::Published)
            ->latest('effective_from')->latest('created_at')->value('id');

        if ($versionId === null) {
            return [$organizationId];
        }

        $ancestors = OrganizationClosurePath::query()
            ->where('hierarchy_version_id', $versionId)
            ->where('descendant_organization_id', $organizationId)
            ->orderBy('depth')
            ->pluck('ancestor_organization_id')
            ->all();

        return array_values(array_unique([$organizationId, ...$ancestors]));
    }

    /** Users (active, linked to an employee) currently serving on a committee. */
    public function committeeUsers(GrievanceCommittee $committee): array
    {
        $employeeIds = $committee->members()->servingOn(now())->pluck('employee_id')->all();

        return User::query()->whereIn('employee_id', $employeeIds)->where('status', 'active')->get()->all();
    }

    /**
     * Users placed in a unit (or beneath it) who hold a permission — e.g. the
     * officers who assign a Team's cases.
     *
     * @return list<User>
     */
    public function unitUsersWithPermission(string $unitId, string $permission): array
    {
        $unitIds = OrganizationUnit::query()->whereKey($unitId)->orWhere('parent_unit_id', $unitId)->pluck('id')->all();
        $employeeIds = EmployeeAssignment::query()
            ->whereIn('organization_unit_id', $unitIds)
            ->where('is_current', true)
            ->where('assignment_status', AssignmentStatus::Active->value)
            ->pluck('employee_id')->all();

        return User::query()->whereIn('employee_id', $employeeIds)->where('status', 'active')->get()
            ->filter(fn (User $user) => $user->can($permission))->values()->all();
    }

    private function organizationModelOf(GrievanceHandlerType $type, Model $model): ?Organization
    {
        return match ($type) {
            GrievanceHandlerType::Organization => $model instanceof Organization ? $model : null,
            default => $model->getAttribute('organization_id') ? Organization::query()->find($model->getAttribute('organization_id')) : null,
        };
    }
}
