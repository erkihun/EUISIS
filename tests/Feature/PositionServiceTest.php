<?php

declare(strict_types=1);

use App\Enums\AssignmentStatus;
use App\Enums\EmployeeStatus;
use App\Enums\OrganizationScopeType;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\EmployeeServiceFeedback;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\PositionService;
use App\Models\ServiceType;
use App\Models\User;
use App\Models\UserOrganizationScope;
use App\Services\ServiceFeedback\EmployeeFeedbackTokenService;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/** An organization with one position and an employee assigned to it. */
function positionOrg(string $prefix): array
{
    $type = OrganizationType::query()->firstOrCreate(
        ['code' => 'PST-TYPE'],
        ['name_en' => 'Position Service Type'],
    );

    $org = Organization::query()->create([
        'organization_type_id' => $type->id,
        'code' => $prefix.'-ORG',
        'name_en' => $prefix.' Organization',
        'status' => 'active',
    ]);

    $unit = OrganizationUnit::query()->create([
        'organization_id' => $org->id,
        'code' => $prefix.'-U1',
        'name_en' => $prefix.' Unit',
        'unit_type' => 'department',
        'status' => 'active',
    ]);

    $position = Position::query()->create([
        'organization_id' => $org->id,
        'organization_unit_id' => $unit->id,
        'job_position_code' => $prefix.'-P1',
        'title_en' => $prefix.' Officer',
        'is_active' => true,
    ]);

    $employee = Employee::query()->create([
        'employee_number' => $prefix.'-EMP',
        'first_name' => 'Pos',
        'last_name' => $prefix,
        'full_name' => 'Pos '.$prefix,
        'status' => EmployeeStatus::Active->value,
    ]);

    $assignment = EmployeeAssignment::query()->create([
        'employee_id' => $employee->id,
        'organization_id' => $org->id,
        'organization_unit_id' => $unit->id,
        'position_id' => $position->id,
        'is_current' => true,
        'assignment_status' => AssignmentStatus::Active->value,
        'effective_from' => now()->toDateString(),
    ]);

    $employee->forceFill(['current_assignment_id' => $assignment->id])->save();

    $token = app(EmployeeFeedbackTokenService::class)->ensureActiveTokenFor($employee);

    RateLimiter::clear('sf-submit:'.$token->token.'|127.0.0.1');
    RateLimiter::clear('sf-submit-ip:127.0.0.1');

    return compact('org', 'unit', 'position', 'employee', 'token');
}

beforeEach(function (): void {
    app()->setLocale('en');

    foreach (['service_feedback.settings.manage', 'service_feedback.view'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    Role::findOrCreate('Super Admin', 'web')->syncPermissions(Permission::all());
    Role::findOrCreate('Organizational Admin', 'web')->syncPermissions(Permission::all());
    Role::findOrCreate('Employee', 'web');

    $this->alpha = positionOrg('ALPHA');
    $this->beta = positionOrg('BETA');

    $this->admin = User::factory()->create();
    $this->admin->assignRole('Super Admin');
});

/** Create a service delivered by a position. */
function makeService(array $ctx, string $serviceNo, string $name, array $overrides = []): PositionService
{
    return PositionService::query()->create(array_merge([
        'organization_id' => $ctx['org']->id,
        'position_id' => $ctx['position']->id,
        'service_no' => $serviceNo,
        'name_en' => $name,
        'is_active' => true,
        'is_performance_evaluation_enabled' => true,
        'sort_order' => 0,
    ], $overrides));
}

it('creates a position service through the crud endpoint', function (): void {
    $this->actingAs($this->admin)
        ->post(route('position-services.store'), [
            'organization_id' => $this->alpha['org']->id,
            'position_id' => $this->alpha['position']->id,
            'service_no' => 'HR-001',
            'name_en' => 'Employee Record Correction',
            'name_am' => 'የሠራተኛ መዝገብ ማስተካከያ',
            'is_active' => true,
            'is_performance_evaluation_enabled' => true,
            'sort_order' => 1,
        ])
        ->assertRedirect(route('position-services.index'));

    $service = PositionService::query()->firstOrFail();

    expect($service->service_no)->toBe('HR-001')
        ->and($service->name_en)->toBe('Employee Record Correction')
        ->and($service->position_id)->toBe($this->alpha['position']->id)
        ->and($service->organization_id)->toBe($this->alpha['org']->id);
});

it('requires a service number and english name', function (): void {
    $this->actingAs($this->admin)
        ->post(route('position-services.store'), [
            'organization_id' => $this->alpha['org']->id,
            'position_id' => $this->alpha['position']->id,
        ])
        ->assertSessionHasErrors(['service_no', 'name_en']);
});

it('lists position services for an authorised user', function (): void {
    makeService($this->alpha, 'HR-001', 'Employee Record Correction');

    $this->actingAs($this->admin)
        ->get(route('position-services.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('PositionServices/Index')
            ->where('records.data.0.service_no', 'HR-001')
            ->where('records.data.0.name_en', 'Employee Record Correction')
        );
});

it('updates a position service', function (): void {
    $service = makeService($this->alpha, 'HR-001', 'Record Correction');

    $this->actingAs($this->admin)
        ->patch(route('position-services.update', $service->id), [
            'organization_id' => $this->alpha['org']->id,
            'position_id' => $this->alpha['position']->id,
            'service_no' => 'HR-010',
            'name_en' => 'Renamed Service',
            'is_active' => false,
            'is_performance_evaluation_enabled' => false,
        ])
        ->assertRedirect();

    $service->refresh();

    expect($service->service_no)->toBe('HR-010')
        ->and($service->name_en)->toBe('Renamed Service')
        ->and($service->is_active)->toBeFalse();
});

it('deletes a position service that has no feedback', function (): void {
    $service = makeService($this->alpha, 'HR-001', 'Record Correction');

    $this->actingAs($this->admin)
        ->delete(route('position-services.destroy', $service->id))
        ->assertRedirect();

    expect(PositionService::query()->count())->toBe(0);
});

it('rejects a duplicate service number within one position', function (): void {
    makeService($this->alpha, 'HR-001', 'Record Correction');

    $this->actingAs($this->admin)
        ->post(route('position-services.store'), [
            'organization_id' => $this->alpha['org']->id,
            'position_id' => $this->alpha['position']->id,
            'service_no' => 'HR-001',
            'name_en' => 'Another Service',
        ])
        ->assertSessionHasErrors('service_no');

    expect(PositionService::query()->count())->toBe(1);
});

it('allows the same service number in different positions', function (): void {
    makeService($this->alpha, 'HR-001', 'Alpha Record Correction');

    $this->actingAs($this->admin)
        ->post(route('position-services.store'), [
            'organization_id' => $this->beta['org']->id,
            'position_id' => $this->beta['position']->id,
            'service_no' => 'HR-001',
            'name_en' => 'Beta Record Correction',
        ])
        ->assertRedirect();

    expect(PositionService::query()->count())->toBe(2);
});

it('shows only the position services on the public feedback page', function (): void {
    makeService($this->alpha, 'HR-001', 'Employee Record Correction');
    makeService($this->alpha, 'HR-002', 'ID Card Service');
    // Belongs to a different position entirely.
    makeService($this->beta, 'LIC-001', 'Land Permit');

    $this->get('/service-feedback/'.$this->alpha['token']->token)
        ->assertOk()
        ->assertInertia(function (Assert $page): void {
            $options = collect($page->toArray()['props']['serviceTypes']);

            expect($options)->toHaveCount(2)
                ->and($options->pluck('service_no')->all())->toBe(['HR-001', 'HR-002'])
                ->and($options->pluck('name'))->not->toContain('Land Permit');
        });
});

it('shows an empty list when the position provides no services', function (): void {
    $this->get('/service-feedback/'.$this->alpha['token']->token)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('serviceTypes', []));
});

it('hides a deactivated service from the public page', function (): void {
    makeService($this->alpha, 'HR-001', 'Record Correction', ['is_active' => false]);

    $this->get('/service-feedback/'.$this->alpha['token']->token)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('serviceTypes', []));
});

it('rejects a submission naming another position service', function (): void {
    makeService($this->alpha, 'HR-001', 'Record Correction');
    $foreign = makeService($this->beta, 'LIC-001', 'Land Permit');

    $this->post('/service-feedback/'.$this->alpha['token']->token, [
        'position_service_id' => $foreign->id,
        'rating' => 5,
    ])->assertSessionHasErrors('position_service_id');

    // An officer must never be rated on work their post does not do.
    expect(EmployeeServiceFeedback::query()->count())->toBe(0);
});

it('accepts a submission for the position own service', function (): void {
    $service = makeService($this->alpha, 'HR-001', 'Record Correction');

    $this->post('/service-feedback/'.$this->alpha['token']->token, [
        'position_service_id' => $service->id,
        'rating' => 4,
    ])->assertRedirect();

    expect(EmployeeServiceFeedback::query()->count())->toBe(1);
});

it('stores the service number and name as a snapshot', function (): void {
    $service = makeService($this->alpha, 'HR-001', 'Employee Record Correction');

    $this->post('/service-feedback/'.$this->alpha['token']->token, [
        'position_service_id' => $service->id,
        'rating' => 5,
    ])->assertRedirect();

    $feedback = EmployeeServiceFeedback::query()->firstOrFail();

    expect($feedback->position_service_id)->toBe($service->id)
        ->and($feedback->service_no_snapshot)->toBe('HR-001')
        ->and($feedback->service_name_snapshot)->toBe('Employee Record Correction')
        ->and($feedback->position_id)->toBe($this->alpha['position']->id);
});

it('keeps the snapshot after the service is renamed', function (): void {
    $service = makeService($this->alpha, 'HR-001', 'Employee Record Correction');

    $this->post('/service-feedback/'.$this->alpha['token']->token, [
        'position_service_id' => $service->id,
        'rating' => 3,
    ])->assertRedirect();

    $service->forceFill(['service_no' => 'HR-999', 'name_en' => 'Completely Renamed'])->save();

    // History must describe what the client actually rated at the time.
    $feedback = EmployeeServiceFeedback::query()->firstOrFail();

    expect($feedback->service_no_snapshot)->toBe('HR-001')
        ->and($feedback->service_name_snapshot)->toBe('Employee Record Correction');
});

it('locks the service number once feedback exists', function (): void {
    $service = makeService($this->alpha, 'HR-001', 'Record Correction');

    EmployeeServiceFeedback::query()->create([
        'employee_id' => $this->alpha['employee']->id,
        'organization_id' => $this->alpha['org']->id,
        'position_id' => $this->alpha['position']->id,
        'position_service_id' => $service->id,
        'service_no_snapshot' => 'HR-001',
        'rating' => 4,
        'status' => 'pending',
    ]);

    $scoped = User::factory()->create();
    $scoped->assignRole('Organizational Admin');

    UserOrganizationScope::query()->create([
        'user_id' => $scoped->id,
        'organization_id' => $this->alpha['org']->id,
        'scope_type' => OrganizationScopeType::Self,
    ]);

    $this->actingAs($scoped)
        ->patch(route('position-services.update', $service->id), [
            'organization_id' => $this->alpha['org']->id,
            'position_id' => $this->alpha['position']->id,
            'service_no' => 'HR-777',
            'name_en' => 'Record Correction',
        ]);

    expect($service->fresh()->service_no)->toBe('HR-001');
});

it('refuses to delete a service that has feedback', function (): void {
    $service = makeService($this->alpha, 'HR-001', 'Record Correction');

    EmployeeServiceFeedback::query()->create([
        'employee_id' => $this->alpha['employee']->id,
        'organization_id' => $this->alpha['org']->id,
        'position_id' => $this->alpha['position']->id,
        'position_service_id' => $service->id,
        'rating' => 2,
        'status' => 'pending',
    ]);

    $this->actingAs($this->admin)
        ->delete(route('position-services.destroy', $service->id))
        ->assertSessionHasErrors('service_no');

    // Deactivating is the safe alternative; history is never stranded.
    expect(PositionService::query()->count())->toBe(1);
});

it('confines an organizational admin to their own organization', function (): void {
    makeService($this->alpha, 'HR-001', 'Alpha Service');
    makeService($this->beta, 'LIC-001', 'Beta Service');

    $scoped = User::factory()->create();
    $scoped->assignRole('Organizational Admin');

    UserOrganizationScope::query()->create([
        'user_id' => $scoped->id,
        'organization_id' => $this->alpha['org']->id,
        'scope_type' => OrganizationScopeType::Self,
    ]);

    $this->actingAs($scoped)
        ->get(route('position-services.index'))
        ->assertOk()
        ->assertInertia(function (Assert $page): void {
            $rows = collect($page->toArray()['props']['records']['data']);

            expect($rows)->toHaveCount(1)
                ->and($rows->first()['service_no'])->toBe('HR-001');
        });
});

it('blocks an organizational admin from creating outside scope', function (): void {
    $scoped = User::factory()->create();
    $scoped->assignRole('Organizational Admin');

    UserOrganizationScope::query()->create([
        'user_id' => $scoped->id,
        'organization_id' => $this->alpha['org']->id,
        'scope_type' => OrganizationScopeType::Self,
    ]);

    $this->actingAs($scoped)
        ->post(route('position-services.store'), [
            'organization_id' => $this->beta['org']->id,
            'position_id' => $this->beta['position']->id,
            'service_no' => 'LIC-001',
            'name_en' => 'Land Permit',
        ])
        ->assertForbidden();

    expect(PositionService::query()->count())->toBe(0);
});

it('blocks a user without the manage permission', function (): void {
    $outsider = User::factory()->create();
    $outsider->assignRole('Employee');

    $this->actingAs($outsider)->get(route('position-services.index'))->assertForbidden();
    $this->actingAs($outsider)->get(route('position-services.create'))->assertForbidden();
});

it('does not change the feedback qr token when services are edited', function (): void {
    $before = $this->alpha['token']->token;

    $service = makeService($this->alpha, 'HR-001', 'Record Correction');
    $service->forceFill(['service_no' => 'HR-500', 'is_active' => false])->save();

    expect($this->alpha['employee']->fresh()->activeFeedbackToken->token)->toBe($before);
});

it('is completely independent of the entitlements service type catalog', function (): void {
    $before = ServiceType::query()->count();

    makeService($this->alpha, 'HR-001', 'Record Correction');

    // Position services are their own domain; creating one must not touch the
    // entitlements catalog that providers and entitlements depend on.
    expect(ServiceType::query()->count())->toBe($before);
});

it('records the acting user without a type mismatch', function (): void {
    /*
     * `users.id` is a bigint while most keys in this project are UUIDs. The
     * actor columns were first declared uuid, which made every real save throw
     * "invalid input syntax for type uuid" — this pins the column type to the
     * one `Auth::id()` actually returns.
     */
    $this->actingAs($this->admin)
        ->post(route('position-services.store'), [
            'organization_id' => $this->alpha['org']->id,
            'position_id' => $this->alpha['position']->id,
            'service_no' => '009',
            'name_en' => 'service 1',
            'name_am' => 'service 1',
            'description' => 'service 1',
            'is_active' => true,
            'is_performance_evaluation_enabled' => true,
            'sort_order' => 0,
        ])
        ->assertRedirect(route('position-services.index'));

    $service = PositionService::query()->firstOrFail();

    expect($service->created_by)->not->toBeNull()
        ->and((string) $service->created_by)->toBe((string) $this->admin->getKey());
});
