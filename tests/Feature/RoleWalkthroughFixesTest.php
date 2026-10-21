<?php

declare(strict_types=1);

use App\Enums\AssignmentStatus;
use App\Enums\EmployeeStatus;
use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\HierarchyVersion;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\User;
use App\Models\UserOrganizationScope;
use App\Services\Security\MfaRequirementService;
use App\Support\Communication\CommunicationRoles;
use App\Support\EmployeeScanPhoto;
use App\Support\Rbac\DefaultRoleMatrix;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CommunicationScenario;

/*
 * Regressions from the role-by-role walkthrough of 2026-10-21
 * (docs/qa/e2e-role-walkthrough-2026-10-21.md, defects R1-R8).
 */

/** A 1x1 transparent PNG. */
function walkthroughPng(): string
{
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
}

function walkthroughEmployee(bool $withPhoto = false): Employee
{
    $type = OrganizationType::query()->firstOrCreate(['code' => 'RW-T'], ['name_en' => 'Walkthrough type']);
    $org = Organization::query()->firstOrCreate(['code' => 'RW-ORG'], ['organization_type_id' => $type->id, 'name_en' => 'Walkthrough org', 'status' => 'active']);
    $version = HierarchyVersion::query()->firstOrCreate(['version_name' => 'rw-v1'], ['status' => 'published']);
    $employee = Employee::query()->create([
        'employee_number' => 'RW-'.Str::upper(Str::random(6)), 'first_name' => 'Role', 'last_name' => 'Walk',
        'full_name' => 'Role Walk', 'status' => EmployeeStatus::Active,
    ]);
    $assignment = EmployeeAssignment::query()->create([
        'employee_id' => $employee->id, 'organization_id' => $org->id, 'hierarchy_version_id' => $version->id,
        'assignment_status' => AssignmentStatus::Active, 'effective_from' => now()->toDateString(), 'is_current' => true,
    ]);
    $employee->update(['current_assignment_id' => $assignment->id]);

    if ($withPhoto) {
        $path = 'employee-photos/'.Str::uuid().'.png';
        Storage::disk('local')->put($path, walkthroughPng());
        $employee->update(['photo_path' => $path]);
    }

    return $employee->fresh();
}

function walkthroughUser(string $role): User
{
    return User::factory()->create(['status' => 'active', 'must_change_password' => false])->assignRole($role);
}

// R1 ─────────────────────────────────────────────────────────────────────────

it('opens the Communication home for roles without announcements.view', function (string $role, array $permissions): void {
    $s = CommunicationScenario::build();
    $user = CommunicationScenario::employeeUser('Ivy Investigator', $s->orgA, $s->unitA1, $permissions, $role);
    expect($user->can('announcements.view'))->toBeFalse();

    $this->actingAs($user)->get(route('communication.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Communication/Index')->where('announcements', [])->where('pendingAcknowledgements', []));
})->with([
    'investigator' => ['Comm Test Investigator', CommunicationRoles::INVESTIGATOR_PERMISSIONS],
    'privacy officer' => ['Comm Test Privacy Officer', CommunicationRoles::PRIVACY_OFFICER_PERMISSIONS],
]);

// R2 ─────────────────────────────────────────────────────────────────────────

it('ships an MFA baseline that names real roles and covers every full-administration role', function (): void {
    $baseline = array_map('trim', explode(',', MfaRequirementService::DEFAULT_REQUIRED_ROLES));
    $roles = DefaultRoleMatrix::names();

    expect(array_diff($baseline, $roles))->toBe([]);

    // Any shipped role holding at least the City Admin grant must be in the baseline.
    $cityAdminGrant = count(DefaultRoleMatrix::permissionsFor('City Admin'));
    $fullAdmins = array_values(array_filter($roles, fn (string $role): bool => count(DefaultRoleMatrix::permissionsFor($role)) >= $cityAdminGrant));

    expect($fullAdmins)->toContain('System Admin', 'Public Service Bureau Admin')
        ->and(array_diff($fullAdmins, $baseline))->toBe([]);
});

// R3 ─────────────────────────────────────────────────────────────────────────

it('sends roles without dashboard access to My Portal and tells the sidebar so', function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $officer = walkthroughUser('Performance Officer');

    $this->actingAs($officer)->get(route('dashboard'))->assertRedirect(route('employee.portal'));

    $this->actingAs($officer)->get(route('employee.portal'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.canViewDashboard', false)
            ->where('employee', null)
            ->where('staff_account', true));

    $this->actingAs(walkthroughUser('Report Viewer'))->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('auth.canViewDashboard', true));
});

it('still sends an employee-only account without a record to HR', function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $this->actingAs(walkthroughUser('Employee'))->get(route('employee.portal'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('employee', null)->where('staff_account', false));
});

// R4 ─────────────────────────────────────────────────────────────────────────

it('links private employee photos through the authorized route, not public storage', function (): void {
    Storage::fake('local');
    $employee = walkthroughEmployee(withPhoto: true);

    $url = (new EmployeeResource($employee))->resolve()['photo_url'];

    expect($url)->toBe(route('employees.private-photo', $employee, false))
        ->and($url)->not->toContain('/storage/');
});

it('serves a scan-result photo only through its signed, short-lived link', function (): void {
    Storage::fake('local');
    $employee = walkthroughEmployee(withPhoto: true);
    $url = EmployeeScanPhoto::url($employee);

    expect($url)->not->toBeNull();

    // No session at all: the provider portal signs in on another guard.
    $this->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->get(route('employees.scan-photo', $employee, false))->assertForbidden();
    $this->get(str_replace($employee->id, walkthroughEmployee(withPhoto: true)->id, $url))->assertForbidden();

    $this->travel(EmployeeScanPhoto::TTL_MINUTES + 1)->minutes();
    $this->get($url)->assertForbidden();
});

it('gives no scan-photo link to an employee without a photo', function (): void {
    expect(EmployeeScanPhoto::url(walkthroughEmployee()))->toBeNull()
        ->and(EmployeeScanPhoto::url(null))->toBeNull();
});

// R5 ─────────────────────────────────────────────────────────────────────────

it('lets card staff see the photo without employee registry access, and nobody else', function (): void {
    Storage::fake('local');
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $employee = walkthroughEmployee(withPhoto: true);

    foreach (['ID Card Officer', 'ID Card Approver'] as $role) {
        $user = walkthroughUser($role);
        expect($user->can('employees.view'))->toBeFalse();

        $this->actingAs($user)->get(route('employees.private-photo', $employee))->assertOk();
    }

    $this->actingAs(walkthroughUser('Report Viewer'))->get(route('employees.private-photo', $employee))->assertForbidden();
});

it('keeps card staff inside their organization scope for photos', function (): void {
    Storage::fake('local');
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $employee = walkthroughEmployee(withPhoto: true);

    $type = OrganizationType::query()->firstOrCreate(['code' => 'RW-T'], ['name_en' => 'Walkthrough type']);
    $elsewhere = Organization::query()->create(['organization_type_id' => $type->id, 'code' => 'RW-OTHER', 'name_en' => 'Other org', 'status' => 'active']);
    $approver = walkthroughUser('ID Card Approver');
    UserOrganizationScope::query()->create([
        'user_id' => $approver->id, 'organization_id' => $elsewhere->id, 'scope_type' => 'subtree',
        'effective_from' => now()->subDay()->toDateString(), 'is_active' => true,
    ]);

    $this->actingAs($approver)->get(route('employees.private-photo', $employee))->assertForbidden();
});

// R6 ─────────────────────────────────────────────────────────────────────────

it('marks organization-scoped admins as not citywide, so city-level settings stay hidden', function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $employee = walkthroughEmployee();
    $admin = walkthroughUser('Organizational Admin');
    UserOrganizationScope::query()->create([
        'user_id' => $admin->id, 'organization_id' => $employee->currentAssignment->organization_id, 'scope_type' => 'subtree',
        'effective_from' => now()->subDay()->toDateString(), 'is_active' => true,
    ]);

    expect($admin->can('service_feedback.settings.manage'))->toBeTrue();

    $this->actingAs($admin)->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('auth.unrestrictedScope', false));
    $this->actingAs($admin)->get(route('service-feedback.settings.index'))->assertForbidden();

    $this->actingAs(walkthroughUser('System Admin'))->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('auth.unrestrictedScope', true));
});

// R7 ─────────────────────────────────────────────────────────────────────────

it('gives the Settlement Officer read-only access to the settlement screens', function (): void {
    $grant = DefaultRoleMatrix::permissionsFor('Settlement Officer');

    expect($grant)->toContain('cafeteria_settlements.view', 'transport-settlements.viewAny', 'transport-invoices.viewAny');

    $writes = array_filter($grant, fn (string $p): bool => (bool) preg_match('/\.(manage|approve|create|update|delete|review)$/', $p));
    expect(array_values($writes))->toBe([]);
});
