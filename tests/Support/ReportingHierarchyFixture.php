<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\HierarchyVersionStatus;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\HierarchyVersion;
use App\Models\Organization;
use App\Models\OrganizationEdge;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\Role;
use App\Models\User;
use App\Models\UserOrganizationScope;
use Spatie\Permission\Models\Permission;

/**
 * A small bureau for the reporting-line tests (ADR 0025):
 *
 *   Bureau (organization) — head position: Bureau Head
 *     Sector (unit) — head: Sector Head
 *       Directorate (unit) — head: Director; staff: Directorate Officer
 *         Team (unit) — head: Team Leader; members: Member position (two seats)
 *     Finance (unit, under Sector) — head: Finance Director
 *   Organization-level staff position (no unit): Advisor
 *
 * People are created on demand with `person()`, placed through an active
 * assignment from $from. Nothing here goes through HTTP; the tests that
 * check pages sign in with `user()`.
 */
final class ReportingHierarchyFixture
{
    public const FROM = '2026-01-01';

    public Organization $bureau;

    public OrganizationUnit $sector;

    public OrganizationUnit $directorate;

    public OrganizationUnit $team;

    public OrganizationUnit $finance;

    public Position $bureauHead;

    public Position $sectorHead;

    public Position $director;

    public Position $financeDirector;

    public Position $teamLeader;

    public Position $member;

    public Position $directorateOfficer;

    public Position $advisor;

    private int $sequence = 0;

    public static function build(string $code = 'RL'): self
    {
        $f = new self;
        $type = OrganizationType::query()->firstOrCreate(['code' => $code.'-TYPE'], ['name_en' => 'Bureau', 'name_am' => 'ቢሮ']);
        $f->bureau = Organization::query()->create(['organization_type_id' => $type->id, 'code' => $code.'-BUREAU', 'name_en' => $code.' Bureau', 'name_am' => 'ቢሮ', 'status' => 'active']);

        $f->sector = $f->unit($code.'-SECTOR', 'Sector', null, 'sector');
        $f->directorate = $f->unit($code.'-DIR', 'Directorate', $f->sector, 'directorate');
        $f->team = $f->unit($code.'-TEAM', 'Team', $f->directorate, 'team');
        $f->finance = $f->unit($code.'-FIN', 'Finance', $f->sector, 'directorate');

        $f->bureauHead = $f->position($code.'-P-BH', 'Bureau Head', null, true);
        $f->sectorHead = $f->position($code.'-P-SH', 'Sector Head', $f->sector, true);
        $f->director = $f->position($code.'-P-DIR', 'Director', $f->directorate, true);
        $f->financeDirector = $f->position($code.'-P-FD', 'Finance Director', $f->finance, true);
        $f->teamLeader = $f->position($code.'-P-TL', 'Team Leader', $f->team, true);
        $f->member = $f->position($code.'-P-MEM', 'Team Member', $f->team, false);
        $f->directorateOfficer = $f->position($code.'-P-OFF', 'Directorate Officer', $f->directorate, false);
        $f->advisor = $f->position($code.'-P-ADV', 'Advisor', null, false);

        return $f;
    }

    public function unit(string $code, string $name, ?OrganizationUnit $parent, string $type = 'directorate', string $status = 'active'): OrganizationUnit
    {
        return OrganizationUnit::query()->create([
            'organization_id' => $this->bureau->id, 'parent_unit_id' => $parent?->id, 'unit_type' => $type,
            'code' => $code, 'name_en' => $name, 'name_am' => $name, 'status' => $status,
        ]);
    }

    public function position(string $code, string $title, ?OrganizationUnit $unit, bool $head = false, array $overrides = []): Position
    {
        return Position::query()->create(array_merge([
            'organization_id' => $this->bureau->id, 'organization_unit_id' => $unit?->id, 'job_position_code' => $code,
            'title_en' => $title, 'title_am' => $title, 'is_active' => true, 'is_unit_head' => $head,
        ], $overrides));
    }

    /** An active employee placed in a position from the date (the unit comes from the position). */
    public function person(string $name, Position $position, string $from = self::FROM, ?string $to = null, array $employee = [], array $assignment = []): Employee
    {
        $this->sequence++;
        $number = sprintf('%s-%03d', $this->bureau->code, $this->sequence);
        $record = Employee::query()->create(array_merge([
            'employee_number' => $number, 'first_name' => $name, 'last_name' => 'Tester', 'full_name' => $name.' Tester',
            'email' => strtolower(preg_replace('/[^a-z0-9]+/i', '.', $name)).'.'.$this->sequence.'@rl.test', 'status' => 'active',
        ], $employee));
        $this->place($record, $position, $from, $to, $assignment);

        return $record->fresh();
    }

    public function place(Employee $employee, Position $position, string $from = self::FROM, ?string $to = null, array $overrides = []): EmployeeAssignment
    {
        $row = EmployeeAssignment::query()->create(array_merge([
            'employee_id' => $employee->id,
            'organization_id' => $position->organization_id,
            'organization_unit_id' => $position->organization_unit_id,
            'position_id' => $position->id,
            'assignment_status' => 'active',
            'effective_from' => $from,
            'effective_to' => $to,
            'is_current' => $to === null,
        ], $overrides));
        if ($row->is_current) {
            $employee->forceFill(['current_assignment_id' => $row->id])->save();
        }

        return $row;
    }

    /** A signed-in account for an employee, holding the permissions through a scoped role (own organization only, ADR 0003). */
    public function user(Employee $employee, array $permissions = [], string $role = 'Employee', bool $scopeRow = false): User
    {
        $roleModel = Role::findOrCreate($role, 'web');
        foreach ($permissions as $permission) {
            $roleModel->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $user = User::factory()->create(['email' => $employee->email, 'status' => 'active']);
        $user->forceFill(['employee_id' => $employee->id])->save();
        $user->assignRole($roleModel);
        if ($scopeRow) {
            UserOrganizationScope::query()->create(['user_id' => $user->id, 'organization_id' => $this->bureau->id, 'scope_type' => 'self', 'is_active' => true]);
        }
        $user->forgetCachedPermissions();

        return $user->fresh();
    }

    /** A parent organization with its own head, above the bureau in a published hierarchy version. */
    public function parentOrganization(string $code = 'RL-CITY'): array
    {
        $parent = Organization::query()->create(['organization_type_id' => $this->bureau->organization_type_id, 'code' => $code, 'name_en' => 'City Administration', 'name_am' => 'ከተማ አስተዳደር', 'status' => 'active']);
        $head = Position::query()->create(['organization_id' => $parent->id, 'organization_unit_id' => null, 'job_position_code' => $code.'-P-MAYOR', 'title_en' => 'Mayor', 'title_am' => 'ከንቲባ', 'is_active' => true, 'is_unit_head' => true]);
        $version = HierarchyVersion::query()->create(['version_name' => $code.' v1', 'status' => HierarchyVersionStatus::Published->value, 'approval_date' => now()->subMonth(), 'effective_from' => '2025-01-01']);
        OrganizationEdge::query()->create(['hierarchy_version_id' => $version->id, 'parent_organization_id' => $parent->id, 'child_organization_id' => $this->bureau->id, 'relationship_type' => 'reports_to', 'effective_from' => '2025-01-01']);

        return [$parent, $head, $version];
    }
}
