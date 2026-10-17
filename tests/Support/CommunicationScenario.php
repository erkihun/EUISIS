<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\SystemSettings\SystemSettingsRegistry;
use App\Services\SystemSettings\SystemSettingsService;
use App\Support\Communication\CommunicationRoles;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Communication test world: two organizations (A with two units, B), employees
 * with user accounts and the default employee communication permissions, and
 * helpers for settings and extra roles.
 */
final class CommunicationScenario
{
    public Organization $orgA;

    public Organization $orgB;

    public OrganizationUnit $unitA1;

    public OrganizationUnit $unitA2;

    public OrganizationUnit $unitB;

    public User $alice;   // org A, unit A1

    public User $bob;     // org A, unit A1

    public User $carol;   // org A, unit A2

    public User $dave;    // org B

    public static function build(): self
    {
        config(['security.mfa_enforce' => false, 'broadcasting.default' => 'null']);
        $s = new self;
        $type = OrganizationType::query()->firstOrCreate(['code' => 'COMM-T'], ['name_en' => 'Communication test type']);
        $s->orgA = Organization::query()->create(['organization_type_id' => $type->id, 'code' => 'COMM-A', 'name_en' => 'Civil Service Bureau', 'status' => 'active']);
        $s->orgB = Organization::query()->create(['organization_type_id' => $type->id, 'code' => 'COMM-B', 'name_en' => 'Health Bureau', 'status' => 'active']);
        $s->unitA1 = OrganizationUnit::query()->create(['organization_id' => $s->orgA->id, 'code' => 'COMM-A1', 'name_en' => 'HR Directorate', 'unit_type' => 'directorate', 'status' => 'active']);
        $s->unitA2 = OrganizationUnit::query()->create(['organization_id' => $s->orgA->id, 'code' => 'COMM-A2', 'name_en' => 'ICT Directorate', 'unit_type' => 'directorate', 'status' => 'active']);
        $s->unitB = OrganizationUnit::query()->create(['organization_id' => $s->orgB->id, 'code' => 'COMM-B1', 'name_en' => 'Health HR', 'unit_type' => 'directorate', 'status' => 'active']);

        $s->alice = self::employeeUser('Alice Abebe', $s->orgA, $s->unitA1);
        $s->bob = self::employeeUser('Bob Bekele', $s->orgA, $s->unitA1);
        $s->carol = self::employeeUser('Carol Chala', $s->orgA, $s->unitA2);
        $s->dave = self::employeeUser('Dave Desta', $s->orgB, $s->unitB);

        return $s;
    }

    /** @param list<string>|null $permissions */
    public static function employeeUser(string $name, Organization $org, OrganizationUnit $unit, ?array $permissions = null, string $role = 'Comm Test Employee'): User
    {
        $position = Position::query()->create(['organization_id' => $org->id, 'organization_unit_id' => $unit->id, 'job_position_code' => 'CP-'.Str::upper(Str::random(6)), 'title_en' => 'Officer', 'is_active' => true]);
        $employee = Employee::query()->create(['employee_number' => 'CE-'.Str::upper(Str::random(6)), 'first_name' => $name, 'last_name' => 'Test', 'full_name' => $name, 'name_en' => $name, 'status' => 'active']);
        $assignment = EmployeeAssignment::query()->create([
            'employee_id' => $employee->id, 'organization_id' => $org->id, 'organization_unit_id' => $unit->id, 'position_id' => $position->id,
            'assignment_status' => 'active', 'effective_from' => '2026-01-01', 'is_current' => true,
        ]);
        $employee->update(['current_assignment_id' => $assignment->id]);
        $user = User::factory()->create(['name' => $name, 'status' => 'active', 'employee_id' => $employee->id]);
        $user->assignRole(self::role($role, $permissions ?? CommunicationRoles::EMPLOYEE_PERMISSIONS));

        return $user->fresh();
    }

    /** @param list<string> $permissions */
    public static function role(string $name, array $permissions): Role
    {
        $role = Role::findOrCreate($name, 'web');
        foreach ($permissions as $permission) {
            $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $role;
    }

    public static function setting(string $key, mixed $value): void
    {
        $definition = SystemSettingsRegistry::definition(SystemSettingsRegistry::GROUP_COMMUNICATION, $key);
        SystemSetting::query()->updateOrCreate(
            ['group' => SystemSettingsRegistry::GROUP_COMMUNICATION, 'key' => $key],
            ['value' => is_bool($value) ? ($value ? 'true' : 'false') : (is_array($value) ? json_encode($value) : (string) $value), 'type' => $definition['type'], 'label_en' => $definition['label_en']],
        );
        app(SystemSettingsService::class)->clearCache();
    }
}
