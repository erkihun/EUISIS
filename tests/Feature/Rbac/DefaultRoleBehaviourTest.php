<?php

declare(strict_types=1);

use App\Enums\AssignmentStatus;
use App\Enums\EmployeeStatus;
use App\Enums\OrganizationScopeType;
use App\Enums\OrganizationStatus;
use App\Models\DailyActivityLog;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\IdCard;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\ProviderUser;
use App\Models\Role;
use App\Models\User;
use App\Models\UserOrganizationScope;
use App\Services\Performance\EpmsAccess;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The seeded default roles, exercised through the real routes and policies
 * (docs/default-role-permission-matrix.md). Numbers follow the audit brief
 * (62–70). Permissions say WHAT; scope, ownership and team coverage say
 * WHERE, and are checked alongside.
 */

beforeEach(function (): void {
    config(['security.mfa_enforce' => false]);
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->orgA = drOrg('A');
    $this->orgB = drOrg('B');
});

function drOrg(string $suffix): Organization
{
    $type = OrganizationType::query()->firstOrCreate(['code' => 'DR-TYPE'], ['name_en' => 'DR Bureau', 'is_demo' => false]);

    return Organization::query()->create([
        'organization_type_id' => $type->id, 'code' => 'DR-'.$suffix.'-'.uniqid(), 'name_en' => 'DR Org '.$suffix,
        'status' => OrganizationStatus::Active, 'effective_from' => now()->subDay()->toDateString(), 'is_demo' => false,
    ]);
}

function drUser(string $role, ?Organization $scope = null, ?string $email = null): User
{
    $user = User::factory()->create(array_filter(['email' => $email, 'status' => 'active']));
    $user->assignRole($role);
    if ($scope !== null) {
        UserOrganizationScope::query()->create([
            'user_id' => $user->id, 'organization_id' => $scope->id, 'scope_type' => OrganizationScopeType::Self,
            'is_active' => true, 'effective_from' => now()->subDay()->toDateString(),
        ]);
    }

    return $user->fresh();
}

function drEmployee(Organization $org, ?string $email = null): Employee
{
    $employee = Employee::query()->create([
        'employee_number' => 'DR-'.uniqid(), 'first_name' => 'Test', 'last_name' => 'Employee', 'full_name' => 'Test Employee',
        'email' => $email, 'status' => EmployeeStatus::Active, 'is_demo' => false,
    ]);
    $assignment = EmployeeAssignment::query()->create([
        'employee_id' => $employee->id, 'organization_id' => $org->id, 'assignment_status' => AssignmentStatus::Active,
        'effective_from' => now()->subMonth()->toDateString(), 'is_current' => true,
    ]);
    $employee->update(['current_assignment_id' => $assignment->id]);

    return $employee->fresh();
}

// ── 62: Super Admin ──────────────────────────────────────────────────────────

test('9 11 Super Admin performs protected system actions but cannot delete their own account', function (): void {
    $super = drUser('Super Admin');

    $this->actingAs($super)->get(route('roles.index'))->assertOk();
    $this->actingAs($super)->get(route('system-settings.index'))->assertOk();
    $this->actingAs($super)->get(route('users.index'))->assertOk();
    // Self-protection is not bypassed by the Super Admin gate.
    expect($super->can('delete', $super))->toBeFalse()
        ->and($super->can('archive', $super))->toBeFalse()
        ->and($super->can('assignRoles', $super))->toBeFalse()
        ->and($super->can('archive', drUser('HR Officer')))->toBeTrue();
});

test('10 37 secrets never serialize: password, MFA secret, national ID, card token and QR payload are hidden', function (): void {
    $super = drUser('Super Admin');

    expect(array_keys($super->toArray()))->not->toContain('password', 'two_factor_secret', 'two_factor_recovery_codes', 'national_id', 'remember_token')
        ->and((new IdCard)->getHidden())->toContain('token_hash', 'qr_payload');
});

test('City Admin runs the city but not security settings, role authoring, API credentials or provider-portal sign-in', function (): void {
    $city = drUser('City Admin');
    $security = drUser('Security Settings Manager');

    expect($city->can('employees.manage'))->toBeTrue()
        ->and($city->can('system-settings.manageSecurity'))->toBeFalse()
        ->and($city->can('roles.assignPermissions'))->toBeFalse()
        ->and($city->can('api_management.view'))->toBeFalse()
        ->and($city->can('recycle-bin.forceDelete'))->toBeFalse()
        ->and($city->hasProviderPortalAccess())->toBeFalse()
        ->and($security->can('system-settings.manageSecurity'))->toBeTrue()
        ->and($security->can('employees.manage'))->toBeFalse();
});

// ── 63: Organizational Admin ─────────────────────────────────────────────────

test('12 13 Organizational Admin manages employees in its own scope only', function (): void {
    $admin = drUser('Organizational Admin', $this->orgA);
    $own = drEmployee($this->orgA);
    $other = drEmployee($this->orgB);

    expect($admin->can('update', $own))->toBeTrue()
        ->and($admin->can('update', $other))->toBeFalse();
    $this->actingAs($admin)->get(route('employees.show', $other))->assertForbidden();
    $this->actingAs($admin)->get(route('organizations.show', $this->orgB))->assertForbidden();
});

test('14 15 Organizational Admin requests structure changes but cannot edit the structure directly', function (): void {
    $admin = drUser('Organizational Admin', $this->orgA);

    expect($admin->can('organizational-change-requests.create'))->toBeTrue()
        ->and($admin->can('organization-units.request_create'))->toBeTrue()
        ->and($admin->can('organization-units.create'))->toBeFalse()
        ->and($admin->can('positions.update'))->toBeFalse();
    $this->actingAs($admin)->get(route('organizational-change-requests.create'))->assertOk();
    $this->actingAs($admin)->post(route('organization-units.store'), ['organization_id' => $this->orgA->id, 'name_en' => 'Direct'])->assertForbidden();
});

test('16 17 Organizational Admin cannot hand out global roles or widen their own scope', function (): void {
    $admin = drUser('Organizational Admin', $this->orgA);

    foreach (['Super Admin', 'City Admin', 'Security Settings Manager', 'System Admin'] as $role) {
        expect(Role::findByName($role, 'web')->canBeAssignedBy($admin))->toBeFalse();
    }
    expect($admin->can('assignOrganizationScope', $admin))->toBeFalse()
        ->and($admin->can('assignRoles', $admin))->toBeFalse()
        ->and($admin->can('audit.view'))->toBeFalse()
        ->and($admin->can('employees.viewPii'))->toBeFalse();
});

// ── 64: Employee ─────────────────────────────────────────────────────────────

test('18 19 24 an employee opens their own portal but no admin page and cannot edit official HR records', function (): void {
    $employee = drEmployee($this->orgA, 'self.service@dr.test');
    $user = drUser('Employee', null, 'self.service@dr.test');

    $this->actingAs($user)->get(route('employee.portal'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('is_employee_user', true));
    $this->actingAs($user)->get(route('users.index'))->assertForbidden();
    $this->actingAs($user)->get(route('system-settings.index'))->assertForbidden();
    $this->actingAs($user)->get(route('performance.agreements.index'))->assertForbidden();
    $this->actingAs($user)->patch(route('employees.update', $employee), ['first_name' => 'Changed'])->assertForbidden();
    expect($employee->fresh()->first_name)->toBe('Test');
});

test('20 21 22 23 an employee keeps their own daily activity and performance, never someone else\'s', function (): void {
    $mine = drEmployee($this->orgA, 'owner.da@dr.test');
    $theirs = drEmployee($this->orgA);
    $user = drUser('Employee', null, 'owner.da@dr.test');
    $log = function (Employee $e): DailyActivityLog {
        $log = new DailyActivityLog;
        $log->forceFill(['employee_id' => $e->id, 'organization_id' => $this->orgA->id, 'activity_date' => now()->toDateString()])->save();

        return $log;
    };

    expect($user->can('daily_activities.create'))->toBeTrue()
        ->and($user->can('daily_activities.submit'))->toBeTrue()
        ->and($user->can('view', $log($mine)))->toBeTrue()
        ->and($user->can('view', $log($theirs)))->toBeFalse()
        ->and($user->can('employee_performance_agreements.view_own'))->toBeTrue()
        ->and($user->can('employee_performance_agreements.manage'))->toBeFalse()
        ->and($user->can('performance_reports.view'))->toBeFalse();
});

// ── 65: Manager ──────────────────────────────────────────────────────────────

test('25 26 27 28 team roles review and manage their team, and cannot calibrate', function (): void {
    $reviewer = drUser('Daily Activity Reviewer');
    $manager = drUser('Performance Manager');

    expect($reviewer->can('daily_activities.review'))->toBeTrue()
        ->and($reviewer->can('daily_activities.view_scoped'))->toBeFalse()
        ->and($manager->can('employee_performance_agreements.manage'))->toBeTrue()
        ->and($manager->can('performance_calibration.manage'))->toBeFalse()
        ->and($manager->can('employees.manage'))->toBeFalse();
});

// ── 66: Structure workflow ───────────────────────────────────────────────────

test('29 30 31 32 33 requester, reviewer and implementer stay separate', function (): void {
    $requester = drUser('Structure Change Requester', $this->orgA);
    $reviewer = drUser('Structure Change Reviewer', $this->orgA);
    $implementer = drUser('Structure Implementation Officer', $this->orgA);

    expect($requester->can('organizational-change-requests.approve'))->toBeFalse()
        ->and($requester->can('organizational-change-requests.approve_own'))->toBeFalse()
        ->and($reviewer->can('organizational-change-requests.approve'))->toBeTrue()
        ->and($reviewer->can('organizational-change-requests.implement'))->toBeFalse()
        ->and($reviewer->can('organization-units.update'))->toBeFalse()
        ->and($implementer->can('organizational-change-requests.implement'))->toBeTrue()
        ->and($implementer->can('organizational-change-requests.approve'))->toBeFalse()
        ->and($implementer->can('hierarchy-versions.publish'))->toBeFalse();
    $this->actingAs($requester)->post(route('organization-units.store'), ['organization_id' => $this->orgA->id, 'name_en' => 'Direct'])->assertForbidden();
});

// ── 67: ID cards ─────────────────────────────────────────────────────────────

test('34 35 ID card preparation and approval are separate duties', function (): void {
    $officer = drUser('ID Card Officer', $this->orgA);
    $approver = drUser('ID Card Approver', $this->orgA);
    $admin = drUser('Organizational Admin', $this->orgA);

    expect($officer->can('id-cards.print'))->toBeTrue()
        ->and($officer->can('id-cards.approveRequest'))->toBeFalse()
        ->and($officer->can('id-cards.revoke'))->toBeFalse()
        ->and($approver->can('id-cards.approveRequest'))->toBeTrue()
        ->and($approver->can('id-cards.print'))->toBeFalse()
        ->and($admin->can('id-cards.submitRequest'))->toBeTrue()
        ->and($admin->can('id-cards.approveRequest'))->toBeFalse()
        ->and($admin->can('id-cards.print'))->toBeFalse();
});

// ── 68: Providers ────────────────────────────────────────────────────────────

test('38 39 40 41 provider staff stay in their portal and only operators with a grant use a service', function (): void {
    // Provider accounts live in their own table and guard; no admin role applies to them.
    $operator = (new ProviderUser)->forceFill(['id' => 900001, 'name' => 'Scanner', 'provider_role' => 'operator']);
    $owner = (new ProviderUser)->forceFill(['id' => 900002, 'name' => 'Owner', 'provider_role' => 'owner']);

    expect($operator->canUseServicePermission('provider.transport.routes.manage'))->toBeFalse()
        ->and($owner->canUseServicePermission('provider.transport.routes.manage'))->toBeTrue();
    // A provider session is not an admin session.
    $this->actingAs($operator, 'provider')->get(route('users.index'))->assertRedirect();
    $this->actingAs($operator, 'provider')->get(route('roles.index'))->assertRedirect();
})->skip(fn () => ! class_exists(ProviderUser::class), 'provider portal not installed');

test('service and cafeteria admins can open the pages their permissions promise', function (): void {
    $cafeteria = drUser('Cafeteria Admin');
    $providers = drUser('Service Provider Manager');

    foreach (['cafeteria.dashboard', 'cafeteria.transactions.index', 'cafeteria.reports.index', 'cafeteria.ledger.index', 'cafeteria.holidays.index'] as $page) {
        $this->actingAs($cafeteria)->get(route($page))->assertOk();
    }
    $this->actingAs($providers)->get(route('service-providers.index'))->assertOk();
    $this->actingAs(drUser('Cafeteria Operator'))->get(route('cafeteria.holidays.index'))->assertForbidden();
});

// ── 69: Security ─────────────────────────────────────────────────────────────

test('42 43 roles are managed within the role hierarchy', function (): void {
    $security = drUser('Security Settings Manager');
    $admin = drUser('Organizational Admin', $this->orgA);

    expect($security->can('update', Role::findByName('HR Officer', 'web')))->toBeTrue()
        ->and($security->can('update', Role::findByName('Super Admin', 'web')))->toBeFalse()
        ->and($admin->can('update', Role::findByName('Super Admin', 'web')))->toBeFalse()
        ->and($admin->can('roles.create'))->toBeFalse();
});

test('44 45 46 a missing permission or an out-of-scope record is refused by the server, whatever the URL', function (): void {
    $viewer = drUser('Report Viewer', $this->orgA);
    $admin = drUser('Organizational Admin', $this->orgA);

    $this->actingAs($viewer)->get(route('roles.index'))->assertForbidden();
    $this->actingAs($viewer)->get(route('users.index'))->assertForbidden();
    $this->actingAs($admin)->get(route('employees.show', drEmployee($this->orgB)))->assertForbidden();
});

test('settings admins see which settings groups they may change', function (): void {
    $this->actingAs(drUser('System Settings Admin'))->get(route('system-settings.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('settingGroups.id_cards.can_manage', true)
            ->where('settingGroups.security.can_manage', false)->missing('settingGroups.performance'));
});

// ── 70: EPMS ─────────────────────────────────────────────────────────────────

test('47 48 49 50 51 EPMS duties: officer prepares in scope, reviewer approves, calibrator calibrates, committees see appeals only', function (): void {
    $access = app(EpmsAccess::class);
    $officer = drUser('Performance Officer', $this->orgA);
    $reviewer = drUser('Performance Reviewer', $this->orgA);
    $calibrator = drUser('Performance Calibrator', $this->orgA);
    $committee = drUser('Performance Appeal Committee', $this->orgA);

    expect($access->inScope($officer, 'performance_plans.create', $this->orgA->id))->toBeTrue()
        ->and($access->inScope($officer, 'performance_plans.create', $this->orgB->id))->toBeFalse()
        ->and($officer->can('performance_plans.approve'))->toBeFalse()
        ->and($reviewer->can('performance_plans.approve'))->toBeTrue()
        ->and($reviewer->can('performance_calibration.manage'))->toBeFalse()
        ->and($access->inScope($calibrator, 'performance_calibration.manage', $this->orgA->id))->toBeTrue()
        ->and($calibrator->can('performance_plans.approve'))->toBeFalse()
        ->and($committee->can('performance_appeals.decide'))->toBeTrue()
        ->and($committee->can('employees.manage'))->toBeFalse()
        ->and($committee->can('employees.view'))->toBeFalse();
    $this->actingAs($committee)->patch(route('employees.update', drEmployee($this->orgA)), ['first_name' => 'X'])->assertForbidden();
});
