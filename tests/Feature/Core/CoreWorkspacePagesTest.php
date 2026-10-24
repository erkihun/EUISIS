<?php

declare(strict_types=1);

use App\Enums\AssignmentStatus;
use App\Enums\AuditEventType;
use App\Enums\EmployeeStatus;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\ExternalApplication;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\Position;
use App\Models\User;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;

/*
 * Core workspace Stage 2: the Core-only pages. Each is behind core.access and
 * the permission of what it shows, and reads inside the viewer's scope; the
 * sidebar hiding an entry is never what protects it.
 */

beforeEach(function (): void {
    $type = OrganizationType::query()->create(['code' => 'CP-T', 'name_en' => 'Pages type']);
    $this->mine = Organization::query()->create(['organization_type_id' => $type->id, 'code' => 'CP-MINE', 'name_en' => 'Mine', 'status' => 'active']);
    $this->theirs = Organization::query()->create(['organization_type_id' => $type->id, 'code' => 'CP-THEIRS', 'name_en' => 'Theirs', 'status' => 'active']);
    [$this->employee, $this->assignment] = corePagesPlacement($this->mine, 'CP-E1', 'Abebe Mine');
    corePagesPlacement($this->theirs, 'CP-E2', 'Kebede Theirs');
});

/** @return array{0: Employee, 1: EmployeeAssignment} */
function corePagesPlacement(Organization $organization, string $number, string $name): array
{
    $position = Position::query()->create(['organization_id' => $organization->id, 'job_position_code' => $number.'-P', 'title_en' => 'Officer', 'is_active' => true]);
    $employee = Employee::query()->create(['employee_number' => $number, 'first_name' => 'Core', 'last_name' => 'Pages', 'full_name' => $name, 'status' => EmployeeStatus::Active->value]);
    $assignment = EmployeeAssignment::query()->create(['employee_id' => $employee->id, 'organization_id' => $organization->id, 'position_id' => $position->id, 'is_current' => true, 'assignment_status' => AssignmentStatus::Active->value, 'effective_from' => '2025-01-01']);
    $employee->forceFill(['current_assignment_id' => $assignment->id])->save();

    return [$employee, $assignment];
}

function corePagesUser(array $permissions, ?Organization $scopedTo = null): User
{
    foreach (['core.access', ...$permissions] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $user = User::factory()->create();
    $user->givePermissionTo(['core.access', ...$permissions]);
    if ($scopedTo !== null) {
        $user->organizationScopes()->create(['organization_id' => $scopedTo->id, 'scope_type' => 'self', 'is_active' => true]);
    }

    return $user;
}

it('lists placements inside the viewer\'s scope, with search and history', function (): void {
    $viewer = corePagesUser(['employees.view'], $this->mine);
    EmployeeAssignment::query()->create(['employee_id' => $this->employee->id, 'organization_id' => $this->mine->id, 'is_current' => false, 'assignment_status' => AssignmentStatus::Closed->value, 'effective_from' => '2023-01-01', 'effective_to' => '2024-12-31']);

    $this->actingAs($viewer)->get(route('core.assignments'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Core/Assignments')
            ->where('workspace.name', 'core')
            ->has('assignments', 1)
            ->where('assignments.0.employee.employee_number', 'CP-E1')
            ->where('meta.total', 1));

    $this->get(route('core.assignments', ['history' => 1]))->assertOk()->assertInertia(fn (Assert $page) => $page->has('assignments', 2));
    $this->get(route('core.assignments', ['search' => 'CP-E2']))->assertOk()->assertInertia(fn (Assert $page) => $page->has('assignments', 0));
    $this->get(route('core.assignments', ['search' => 'Abebe']))->assertOk()->assertInertia(fn (Assert $page) => $page->has('assignments', 1));
});

it('refuses each Core page without the permission of what it shows, whatever the sidebar offers', function (): void {
    $plain = corePagesUser([]);

    $this->actingAs($plain)->get(route('core.assignments'))->assertForbidden();
    $this->get(route('core.integration'))->assertForbidden();
    $this->get(route('core.audit'))->assertForbidden();
    $this->get(route('core.settings'))->assertForbidden();

    $outsider = User::factory()->create();
    $this->actingAs($outsider)->get(route('core.audit'))->assertForbidden();
});

it('shows partner applications without any secret, and the event figures only to event readers', function (): void {
    $application = ExternalApplication::query()->create(['name' => 'Canteen app', 'code' => 'CP-APP', 'status' => 'active', 'allowed_scopes' => ['id_cards.verify'], 'rate_limit_per_minute' => 60, 'organization_access' => 'scoped']);
    $plainToken = $application->createToken('cp', ['id_cards.verify'])->plainTextToken;
    $viewer = corePagesUser(['api_management.view']);

    $response = $this->actingAs($viewer)->get(route('core.integration'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Core/IntegrationCenter')
            ->where('events', null)
            ->where('applications.0.code', 'CP-APP')
            ->where('applications.0.scope_count', 1)
            ->where('applications.0.token_count', 1)
            ->where('applications.0', fn ($row) => array_keys($row->all()) === ['id', 'name', 'code', 'status', 'organization_access', 'scope_count', 'token_count', 'next_token_expiry', 'last_used_at']));

    expect($response->getContent())->not->toContain(Str::after($plainToken, '|'))->not->toContain(hash('sha256', Str::after($plainToken, '|')));

    $this->actingAs(corePagesUser(['integrations.view']))->get(route('core.integration'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('applications', null)->has('events.pending_events'));
});

it('counts audit views inside the viewer\'s scope and opens the audit log with the matching filter', function (): void {
    $viewer = corePagesUser(['audit.view'], $this->mine);
    AuditLog::query()->create(['event_type' => AuditEventType::EmployeeUpdated, 'actor_user_id' => $viewer->id, 'organization_id' => $this->mine->id]);
    AuditLog::query()->create(['event_type' => AuditEventType::EmployeeUpdated, 'actor_user_id' => $viewer->id, 'organization_id' => $this->theirs->id]);
    AuditLog::query()->create(['event_type' => AuditEventType::AssignmentChanged, 'actor_user_id' => null, 'organization_id' => $this->mine->id]);

    $this->actingAs($viewer)->get(route('core.audit'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Core/AuditCenter')
            ->where('views', fn ($views) => collect($views)->pluck('count', 'key')->only(['employees', 'assignments', 'machine'])->all() === ['employees' => 1, 'assignments' => 1, 'machine' => 1]));

    // The audit log itself filters by who acted and by correlation id.
    $requestId = (string) Str::uuid();
    AuditLog::query()->create(['event_type' => AuditEventType::EmployeeUpdated, 'actor_user_id' => $viewer->id, 'organization_id' => $this->mine->id, 'request_id' => $requestId]);
    $this->get(route('audit-logs.index', ['request_id' => $requestId]))->assertOk()->assertInertia(fn (Assert $page) => $page->has('auditLogs', 1));
    $this->get(route('audit-logs.index', ['principal' => 'system']))->assertOk()->assertInertia(fn (Assert $page) => $page->has('auditLogs', 1)->where('auditLogs.0.event_type', 'assignment_changed'));
});

it('lists only the settings the viewer may open', function (): void {
    $viewer = corePagesUser(['system-settings.view', 'code-rules.viewAny']);

    $this->actingAs($viewer)->get(route('core.settings'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Core/Settings')
            ->where('sections', fn ($sections) => collect($sections)->pluck('key')->all() === ['general', 'localization', 'appearance', 'security', 'id_cards', 'code_rules']));
});

it('adds the new pages to the Core navigation only for those who may open them', function (): void {
    $viewer = corePagesUser(['employees.view', 'audit.view']);

    $this->actingAs($viewer)->get(route('core.dashboard'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('workspace.nav', function ($nav): bool {
            $routes = collect($nav)->flatMap(fn ($section) => collect($section['groups'])->flatMap(fn ($group) => collect($group['items'])->pluck('route')))->all();

            return in_array('core.assignments', $routes, true) && in_array('core.audit', $routes, true)
                && ! in_array('core.integration', $routes, true) && ! in_array('core.settings', $routes, true);
        }));
});
