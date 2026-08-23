<?php

declare(strict_types=1);

use App\Enums\EmployeeStatus;
use App\Models\ApiEndpointDefinition;
use App\Models\ApiRequestLog;
use App\Models\Employee;
use App\Models\ExternalApplication;
use App\Models\User;
use App\Services\ApiEndpointCatalogService;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $permissions = [
        'api_management.view', 'api_management.create', 'api_management.update',
        'api_management.delete', 'api_management.tokens.create',
        'api_management.tokens.revoke', 'api_management.logs.view',
        'api_management.docs.view', 'api_management.endpoints.view',
        'api_management.endpoints.sync', 'api_management.endpoints.update',
    ];

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    Role::findOrCreate('API Manager', 'web')->givePermissionTo($permissions);
    Role::findOrCreate('Organizational Admin', 'web');

    app(ApiEndpointCatalogService::class)->sync();

    $this->organizations = ApiEndpointDefinition::query()->where('uri', '/api/v1/organizations')->sole();
    // Only endpoints in the `api.external` group pass through the gate.
    $this->cardVerify = ApiEndpointDefinition::query()
        ->where('uri', '/api/v1/id-cards/verify/{token}')
        ->sole();
});

function assignmentManager(): User
{
    $user = User::factory()->create();
    $user->assignRole('API Manager');

    return $user->fresh();
}

/** An application registered with a token, optionally restricted to endpoints. */
function registeredApplication(array $scopes, array $endpointIds = []): array
{
    $application = ExternalApplication::query()->create([
        'name' => 'Cafeteria System',
        'code' => 'CAFE-'.uniqid(),
        'status' => 'active',
        'allowed_scopes' => $scopes,
        'rate_limit_per_minute' => 60,
    ]);

    if ($endpointIds !== []) {
        $application->endpoints()->sync(
            collect($endpointIds)->mapWithKeys(fn (string $id): array => [$id => [
                'id' => (string) Str::uuid(),
                'is_enabled' => true,
            ]])->all()
        );
    }

    return [$application, $application->createToken('test', $scopes)->plainTextToken];
}

it('lists assignable endpoints on the api management page', function (): void {
    $this->actingAs(assignmentManager())
        ->get(route('api-management.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('assignableEndpoints', 9)
            ->where('assignableEndpoints.0.method', fn (string $method): bool => $method !== '')
        );
});

it('excludes deprecated endpoints from the assignable list', function (): void {
    $this->organizations->update(['status' => ApiEndpointDefinition::STATUS_DEPRECATED]);

    $response = $this->actingAs(assignmentManager())->get(route('api-management.index'));
    $uris = collect($response->viewData('page')['props']['assignableEndpoints'])->pluck('uri');

    expect($uris)->not->toContain('/api/v1/organizations');
});

it('excludes undocumented endpoints from the assignable list', function (): void {
    $this->organizations->update(['is_public_documented' => false]);

    $response = $this->actingAs(assignmentManager())->get(route('api-management.index'));
    $uris = collect($response->viewData('page')['props']['assignableEndpoints'])->pluck('uri');

    expect($uris)->not->toContain('/api/v1/organizations');
});

it('saves selected endpoints when creating an application', function (): void {
    $this->actingAs(assignmentManager())
        ->post(route('api-management.store'), [
            'name' => 'Cafeteria System',
            'code' => 'CAFE-NEW',
            'status' => 'active',
            'allowed_scopes' => [],
            'rate_limit_per_minute' => 60,
            'endpoint_ids' => [$this->organizations->id],
        ])
        ->assertRedirect();

    $application = ExternalApplication::query()->where('code', 'CAFE-NEW')->sole();

    expect($application->endpoints)->toHaveCount(1)
        ->and($application->endpoints->first()->uri)->toBe('/api/v1/organizations');
});

it('adds the scope a selected endpoint requires', function (): void {
    $this->actingAs(assignmentManager())
        ->post(route('api-management.store'), [
            'name' => 'Cafeteria System',
            'code' => 'CAFE-SCOPE',
            'status' => 'active',
            // Deliberately empty: selecting the endpoint must grant its scope.
            'allowed_scopes' => [],
            'rate_limit_per_minute' => 60,
            'endpoint_ids' => [$this->organizations->id],
        ])
        ->assertRedirect();

    expect(ExternalApplication::query()->where('code', 'CAFE-SCOPE')->sole()->allowed_scopes)
        ->toContain('reports.read_limited');
});

it('rejects an endpoint id that is not assignable', function (): void {
    $this->organizations->update(['status' => ApiEndpointDefinition::STATUS_DEPRECATED]);

    $this->actingAs(assignmentManager())
        ->post(route('api-management.store'), [
            'name' => 'Cafeteria System',
            'code' => 'CAFE-BAD',
            'status' => 'active',
            'allowed_scopes' => [],
            'rate_limit_per_minute' => 60,
            // A crafted request must not attach a deprecated endpoint.
            'endpoint_ids' => [$this->organizations->id],
        ])
        ->assertSessionHasErrors('endpoint_ids.0');

    expect(ExternalApplication::query()->where('code', 'CAFE-BAD')->exists())->toBeFalse();
});

it('preloads assigned endpoints on the application page', function (): void {
    [$application] = registeredApplication(['reports.read_limited'], [$this->organizations->id]);

    $this->actingAs(assignmentManager())
        ->get(route('api-management.show', $application))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('assignedEndpoints', 1)
            ->where('assignedEndpoints.0.uri', '/api/v1/organizations')
        );
});

it('updates endpoint assignments from the edit form', function (): void {
    [$application] = registeredApplication(['reports.read_limited'], [$this->organizations->id]);

    $this->actingAs(assignmentManager())
        ->patch(route('api-management.update', $application), [
            'name' => $application->name,
            'code' => $application->code,
            'status' => 'active',
            'allowed_scopes' => ['reports.read_limited'],
            'rate_limit_per_minute' => 60,
            'endpoint_ids' => [$this->cardVerify->id],
        ])
        ->assertRedirect();

    expect($application->fresh()->endpoints->pluck('uri')->all())
        ->toBe(['/api/v1/id-cards/verify/{token}']);
});

it('denies application creation to a user without the permission', function (): void {
    $user = User::factory()->create();
    $user->assignRole('Organizational Admin');

    $this->actingAs($user->fresh())
        ->post(route('api-management.store'), [
            'name' => 'Sneaky', 'code' => 'SNEAK-1', 'status' => 'active',
            'allowed_scopes' => [], 'rate_limit_per_minute' => 60,
            'endpoint_ids' => [$this->organizations->id],
        ])
        ->assertForbidden();
});

// ── Runtime enforcement ────────────────────────────────────────────────

it('allows an assigned endpoint with the correct scope', function (): void {
    [, $token] = registeredApplication(['reports.read_limited'], [$this->organizations->id]);

    $this->withToken($token)
        ->getJson('/api/v1/organizations')
        ->assertOk();
});

it('blocks an unassigned endpoint with ENDPOINT_NOT_ALLOWED', function (): void {
    // The token carries id_cards.verify, so scope is not what stops it —
    // the endpoint simply was not assigned. That is the whole point.
    [, $token] = registeredApplication(
        ['reports.read_limited', 'id_cards.verify'],
        [$this->organizations->id],
    );

    $this->withToken($token)
        ->getJson('/api/v1/id-cards/verify/some-card-token')
        ->assertForbidden()
        ->assertJsonPath('error_code', 'endpoint_not_allowed');
});

it('blocks a parameterised endpoint that was not assigned', function (): void {
    [, $token] = registeredApplication(
        ['reports.read_limited', 'service_eligibility.check'],
        [$this->organizations->id],
    );

    // A real employee, because `Employee $employee` is implicitly bound and a
    // missing id would 404 in the router before the gate ever runs.
    $employee = Employee::query()->create([
        'employee_number' => 'GATE-EMP-1',
        'first_name' => 'Abebe',
        'last_name' => 'Bekele',
        'full_name' => 'Abebe Bekele',
        'status' => EmployeeStatus::Active->value,
    ]);

    // Guards the URI-pattern match: the request path carries a concrete id
    // while the catalog stores {employee}.
    $this->withToken($token)
        ->getJson("/api/v1/employees/{$employee->getKey()}/service-eligibility")
        ->assertForbidden()
        ->assertJsonPath('error_code', 'endpoint_not_allowed');
});

it('allows a parameterised endpoint that was assigned', function (): void {
    $eligibility = ApiEndpointDefinition::query()
        ->where('uri', '/api/v1/employees/{employee}/service-eligibility')
        ->sole();

    [, $token] = registeredApplication(['service_eligibility.check'], [$eligibility->id]);

    // Not 403: the endpoint is permitted, so any failure past this point is
    // the controller's own (a missing employee), not the gate's.
    $this->withToken($token)
        ->getJson('/api/v1/employees/999999/service-eligibility')
        ->assertStatus(404);
});

it('leaves an application with no assignments unrestricted', function (): void {
    // Applications registered before endpoint assignment existed must keep
    // working until an administrator narrows them.
    [, $token] = registeredApplication(['reports.read_limited']);

    $this->withToken($token)
        ->getJson('/api/v1/organizations')
        ->assertOk();
});

it('still enforces scope on an assigned endpoint', function (): void {
    [$application] = registeredApplication(['reports.read_limited'], [$this->organizations->id]);
    $weakToken = $application->createToken('weak', ['id_cards.verify'])->plainTextToken;

    $this->withToken($weakToken)
        ->getJson('/api/v1/organizations')
        ->assertForbidden()
        ->assertJsonPath('error_code', 'missing_scope');
});

it('writes an api log when an endpoint is refused', function (): void {
    [$application, $token] = registeredApplication(
        ['reports.read_limited', 'id_cards.verify'],
        [$this->organizations->id],
    );

    $this->withToken($token)->getJson('/api/v1/id-cards/verify/some-card-token');

    $log = ApiRequestLog::query()
        ->where('external_application_id', $application->getKey())
        ->latest('requested_at')
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->success)->toBeFalse()
        ->and($log->failure_reason)->toBe('endpoint_not_allowed')
        ->and($log->status_code)->toBe(403);
});

it('does not consume rate limit quota on a refused endpoint', function (): void {
    [, $token] = registeredApplication(
        ['reports.read_limited', 'id_cards.verify'],
        [$this->organizations->id],
    );

    foreach (range(1, 3) as $ignored) {
        $this->withToken($token)->getJson('/api/v1/id-cards/verify/some-card-token')->assertForbidden();
    }

    // A call the application may never make must not exhaust its budget.
    $this->withToken($token)->getJson('/api/v1/organizations')->assertOk();
});

it('blocks an endpoint whose assignment is disabled', function (): void {
    [$application, $token] = registeredApplication(['reports.read_limited'], [$this->organizations->id]);

    $application->endpoints()->updateExistingPivot($this->organizations->id, ['is_enabled' => false]);

    $this->withToken($token)
        ->getJson('/api/v1/organizations')
        ->assertForbidden()
        ->assertJsonPath('error_code', 'endpoint_not_allowed');
});

it('blocks an assigned endpoint once it is deprecated', function (): void {
    [, $token] = registeredApplication(['reports.read_limited'], [$this->organizations->id]);

    $this->organizations->update(['status' => ApiEndpointDefinition::STATUS_DEPRECATED]);

    $this->withToken($token)
        ->getJson('/api/v1/organizations')
        ->assertForbidden()
        ->assertJsonPath('error_code', 'endpoint_not_allowed');
});

it('removes assignments when the application is deleted', function (): void {
    [$application] = registeredApplication(['reports.read_limited'], [$this->organizations->id]);

    $this->actingAs(assignmentManager())
        ->delete(route('api-management.destroy', $application))
        ->assertRedirect();

    expect(DB::table('external_application_endpoints')
        ->where('external_application_id', $application->getKey())
        ->count())->toBe(0);
});
