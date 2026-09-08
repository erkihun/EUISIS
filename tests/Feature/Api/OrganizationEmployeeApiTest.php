<?php

declare(strict_types=1);

use App\Enums\AssignmentStatus;
use App\Enums\EmployeeStatus;
use App\Models\ApiEndpointDefinition;
use App\Models\ApiRequestLog;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\ExternalApplication;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Services\ApiEndpointCatalogService;
use Illuminate\Support\Str;

/**
 * Organization → unit → position → assignment → employee integration API.
 *
 * Covers the data contract (safe fields only), the runtime gate (endpoint
 * assignment and scope), filtering, pagination and request logging.
 */
beforeEach(function (): void {
    // The catalog is what the gate checks endpoint assignment against, so the
    // routes have to be synced into it before any assignment can be made.
    app(ApiEndpointCatalogService::class)->sync();

    $type = OrganizationType::query()->create(['code' => 'ODATA-TYPE', 'name_en' => 'Data Type']);

    $this->org = Organization::query()->create([
        'organization_type_id' => $type->id,
        'code' => 'ODATA-ORG',
        'name_en' => 'Data Organization',
        'name_am' => 'የዳታ ድርጅት',
        'status' => 'active',
    ]);

    $this->unit = OrganizationUnit::query()->create([
        'organization_id' => $this->org->id,
        'code' => 'ODATA-U1',
        'name_en' => 'Data Unit',
        'name_am' => 'የዳታ ክፍል',
        'unit_type' => 'department',
        'status' => 'active',
    ]);

    $this->position = Position::query()->create([
        'organization_id' => $this->org->id,
        'organization_unit_id' => $this->unit->id,
        'job_position_code' => 'ODATA-P1',
        'title_en' => 'Data Officer',
        'bpr_name' => 'Data Officer BPR',
        'grade_level' => 'IX',
        'is_active' => true,
    ]);

    $this->employee = Employee::query()->create([
        'employee_number' => 'ODATA-EMP-1',
        'first_name' => 'Abebe',
        'last_name' => 'Bekele',
        'full_name' => 'Abebe Bekele',
        'gender' => 'male',
        // The sensitive fields the API must never surface.
        'national_id' => '1234567890123456',
        'phone' => '0911000000',
        'email' => 'abebe@example.com',
        'status' => EmployeeStatus::Active->value,
    ]);

    $assignment = EmployeeAssignment::query()->create([
        'employee_id' => $this->employee->id,
        'organization_id' => $this->org->id,
        'organization_unit_id' => $this->unit->id,
        'position_id' => $this->position->id,
        'is_current' => true,
        'assignment_status' => AssignmentStatus::Active->value,
        'effective_from' => now()->toDateString(),
    ]);

    $this->employee->forceFill(['current_assignment_id' => $assignment->id])->save();
});

/** All six organization-to-employee scopes. */
function orgDataScopes(): array
{
    return [
        'organizations.read',
        'organization_units.read',
        'positions.read',
        'employees.basic_read',
        'employee_assignments.read',
        'organization_structure.read',
    ];
}

/** Catalog rows for the organization-to-employee endpoints. */
function orgDataEndpointIds(): array
{
    return ApiEndpointDefinition::query()
        ->whereIn('required_scope', orgDataScopes())
        ->pluck('id')
        ->all();
}

/**
 * A registered, active application plus a token, assigned the given endpoints.
 *
 * @return array{0: ExternalApplication, 1: string}
 */
function orgDataApplication(?array $scopes = null, ?array $endpointIds = null): array
{
    $application = ExternalApplication::query()->create([
        'name' => 'Structure Consumer',
        'code' => 'STRUCT-'.Str::random(6),
        'status' => 'active',
        'allowed_scopes' => $scopes ?? orgDataScopes(),
        'rate_limit_per_minute' => 120,
    ]);

    $ids = $endpointIds ?? orgDataEndpointIds();

    $application->endpoints()->sync(
        collect($ids)->mapWithKeys(fn (string $id): array => [$id => [
            'id' => (string) Str::uuid(),
            'is_enabled' => true,
        ]])->all()
    );

    $token = $application->createToken('test', $application->grantableScopes())->plainTextToken;

    return [$application, $token];
}

// ── Catalog / API Management ─────────────────────────────────────────────

it('lists the organization-to-employee endpoints in the catalog', function (): void {
    $uris = ApiEndpointDefinition::query()->pluck('uri')->all();

    expect($uris)
        ->toContain('/api/v1/organizations/directory')
        ->toContain('/api/v1/organizations/{organization}')
        ->toContain('/api/v1/organizations/{organization}/units')
        ->toContain('/api/v1/organizations/{organization}/positions')
        ->toContain('/api/v1/organizations/{organization}/employees')
        ->toContain('/api/v1/organizations/{organization}/structure')
        ->toContain('/api/v1/organization-units')
        ->toContain('/api/v1/organization-units/{unit}')
        ->toContain('/api/v1/organization-units/{unit}/positions')
        ->toContain('/api/v1/organization-units/{unit}/employees')
        ->toContain('/api/v1/positions')
        ->toContain('/api/v1/positions/{position}')
        ->toContain('/api/v1/employees')
        ->toContain('/api/v1/employees/{employee}')
        ->toContain('/api/v1/employees/{employee}/assignment');
});

it('records the required scope discovered from each route', function (): void {
    $structure = ApiEndpointDefinition::query()
        ->where('uri', '/api/v1/organizations/{organization}/structure')
        ->sole();

    expect($structure->required_scope)->toBe('organization_structure.read')
        ->and($structure->status)->toBe(ApiEndpointDefinition::STATUS_ACTIVE)
        ->and($structure->version)->toBe('v1');
});

it('groups the new endpoints under the organization and employee data APIs', function (): void {
    $groupOf = fn (string $uri): string => ApiEndpointDefinition::query()->where('uri', $uri)->sole()->documentationGroup();

    expect($groupOf('/api/v1/organizations/{organization}/structure'))->toBe('organization_structure_api')
        ->and($groupOf('/api/v1/organization-units'))->toBe('organization_data_api')
        ->and($groupOf('/api/v1/employees'))->toBe('employee_data_api')
        ->and($groupOf('/api/v1/employees/{employee}/assignment'))->toBe('employee_data_api')
        ->and($groupOf('/api/v1/positions'))->toBe('organization_data_api');
});

// ── Runtime enforcement ──────────────────────────────────────────────────

it('allows an application with the endpoint assigned and the scope granted', function (): void {
    [, $token] = orgDataApplication();

    $this->withToken($token)
        ->getJson('/api/v1/organizations/directory')
        ->assertOk()
        ->assertJsonPath('data.0.code', 'ODATA-ORG');
});

it('returns endpoint_not_allowed when the endpoint is not assigned', function (): void {
    // Every scope is granted, so only the missing assignment can be the cause.
    $onlyOrganizations = ApiEndpointDefinition::query()
        ->where('uri', '/api/v1/organizations/directory')
        ->pluck('id')
        ->all();

    [, $token] = orgDataApplication(orgDataScopes(), $onlyOrganizations);

    $this->withToken($token)
        ->getJson('/api/v1/employees')
        ->assertForbidden()
        ->assertJsonPath('error_code', 'endpoint_not_allowed');
});

it('returns missing_scope when the token lacks the required scope', function (): void {
    // Endpoint assigned, but the token carries only the organization scope.
    [$application] = orgDataApplication();
    $weakToken = $application->createToken('weak', ['organizations.read'])->plainTextToken;

    $this->withToken($weakToken)
        ->getJson('/api/v1/employees')
        ->assertForbidden()
        ->assertJsonPath('error_code', 'missing_scope')
        ->assertJsonPath('required_scope', 'employees.basic_read');
});

it('rejects an unauthenticated caller', function (): void {
    $this->getJson('/api/v1/employees')->assertUnauthorized();
});

it('rejects a suspended application', function (): void {
    [$application, $token] = orgDataApplication();
    $application->update(['status' => 'suspended']);

    $this->withToken($token)
        ->getJson('/api/v1/organizations/directory')
        ->assertForbidden()
        ->assertJsonPath('error_code', 'application_suspended');
});

it('writes an api request log for a successful call', function (): void {
    [$application, $token] = orgDataApplication();

    $this->withToken($token)->getJson('/api/v1/employees')->assertOk();

    $log = ApiRequestLog::query()
        ->where('external_application_id', $application->getKey())
        ->latest('requested_at')
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->success)->toBeTrue()
        ->and($log->status_code)->toBe(200)
        ->and($log->endpoint)->toBe('/api/v1/employees');
});

it('writes an api request log for a denied call', function (): void {
    $onlyOrganizations = ApiEndpointDefinition::query()
        ->where('uri', '/api/v1/organizations/directory')
        ->pluck('id')
        ->all();

    [$application, $token] = orgDataApplication(orgDataScopes(), $onlyOrganizations);

    $this->withToken($token)->getJson('/api/v1/employees')->assertForbidden();

    $log = ApiRequestLog::query()
        ->where('external_application_id', $application->getKey())
        ->latest('requested_at')
        ->first();

    expect($log->success)->toBeFalse()
        ->and($log->failure_reason)->toBe('endpoint_not_allowed')
        ->and($log->status_code)->toBe(403);
});

// ── Data safety ──────────────────────────────────────────────────────────

it('never exposes sensitive employee fields', function (): void {
    [, $token] = orgDataApplication();

    foreach (['/api/v1/employees', "/api/v1/employees/{$this->employee->id}"] as $uri) {
        $body = $this->withToken($token)->getJson($uri)->assertOk()->getContent();

        expect($body)
            ->not->toContain('national_id')
            ->not->toContain('1234567890123456')
            ->not->toContain('phone')
            ->not->toContain('0911000000')
            ->not->toContain('email')
            ->not->toContain('abebe@example.com')
            ->not->toContain('salary')
            ->not->toContain('address')
            ->not->toContain('date_of_birth')
            ->not->toContain('photo_path')
            ->not->toContain('signature_path');
    }
});

it('returns only the agreed safe employee fields', function (): void {
    [, $token] = orgDataApplication();

    $employee = $this->withToken($token)
        ->getJson("/api/v1/employees/{$this->employee->id}")
        ->assertOk()
        ->json('data');

    expect(array_keys($employee))->toEqualCanonicalizing([
        'id', 'employee_number', 'full_name', 'full_name_en', 'gender',
        'employment_status', 'organization_id', 'organization_unit_id',
        'position_id', 'active_assignment', 'updated_at',
    ]);
});

it('returns the agreed safe organization fields', function (): void {
    [, $token] = orgDataApplication();

    $organization = $this->withToken($token)
        ->getJson("/api/v1/organizations/{$this->org->id}")
        ->assertOk()
        ->json('data');

    expect(array_keys($organization))->toEqualCanonicalizing([
        'id', 'code', 'name_en', 'name_am', 'organization_type', 'status', 'updated_at',
    ]);
});

it('reports position occupancy without naming the occupant', function (): void {
    [, $token] = orgDataApplication();

    $position = $this->withToken($token)
        ->getJson("/api/v1/positions/{$this->position->id}")
        ->assertOk()
        ->json('data');

    expect($position['occupied'])->toBeTrue()
        ->and($position['vacant'])->toBeFalse()
        ->and($position['code'])->toBe('ODATA-P1')
        ->and($position['standard_name'])->toBe('Data Officer')
        ->and($position['job_grade'])->toBe('IX')
        ->and($position['position_status'])->toBe('active')
        ->and($position)->not->toHaveKey('employee');
});

it('marks a position with no current assignment as vacant', function (): void {
    $vacant = Position::query()->create([
        'organization_id' => $this->org->id,
        'organization_unit_id' => $this->unit->id,
        'job_position_code' => 'ODATA-P2',
        'title_en' => 'Vacant Post',
        'is_active' => true,
    ]);

    [, $token] = orgDataApplication();

    $this->withToken($token)
        ->getJson("/api/v1/positions/{$vacant->id}")
        ->assertOk()
        ->assertJsonPath('data.vacant', true)
        ->assertJsonPath('data.occupied', false);
});

// ── Structure endpoint ───────────────────────────────────────────────────

it('supports depth=unit', function (): void {
    [, $token] = orgDataApplication();

    $response = $this->withToken($token)
        ->getJson("/api/v1/organizations/{$this->org->id}/structure?depth=unit")
        ->assertOk()
        ->assertJsonPath('data.depth', 'unit')
        ->assertJsonPath('data.units.0.code', 'ODATA-U1');

    // Units only: the tree stops before positions.
    expect($response->json('data.units.0'))->not->toHaveKey('positions');
});

it('supports depth=position', function (): void {
    [, $token] = orgDataApplication();

    $response = $this->withToken($token)
        ->getJson("/api/v1/organizations/{$this->org->id}/structure?depth=position")
        ->assertOk()
        ->assertJsonPath('data.depth', 'position')
        ->assertJsonPath('data.units.0.positions.0.code', 'ODATA-P1');

    expect($response->json('data.units.0.positions.0'))->not->toHaveKey('employees');
});

it('defaults to depth=position', function (): void {
    [, $token] = orgDataApplication();

    $this->withToken($token)
        ->getJson("/api/v1/organizations/{$this->org->id}/structure")
        ->assertOk()
        ->assertJsonPath('data.depth', 'position');
});

it('supports depth=employee with a safe employee summary', function (): void {
    [, $token] = orgDataApplication();

    $response = $this->withToken($token)
        ->getJson("/api/v1/organizations/{$this->org->id}/structure?depth=employee")
        ->assertOk()
        ->assertJsonPath('data.depth', 'employee')
        ->assertJsonPath('data.units.0.positions.0.employees.0.employee_number', 'ODATA-EMP-1');

    expect(array_keys($response->json('data.units.0.positions.0.employees.0')))
        ->toEqualCanonicalizing(['id', 'employee_number', 'full_name', 'employment_status']);

    // The summary must not carry sensitive data either.
    expect($response->getContent())
        ->not->toContain('1234567890123456')
        ->not->toContain('0911000000');
});

it('falls back to depth=position for an unknown depth', function (): void {
    [, $token] = orgDataApplication();

    $this->withToken($token)
        ->getJson("/api/v1/organizations/{$this->org->id}/structure?depth=everything")
        ->assertOk()
        ->assertJsonPath('data.depth', 'position');
});

// ── Filtering and pagination ─────────────────────────────────────────────

it('paginates list endpoints', function (): void {
    foreach (range(1, 4) as $index) {
        Employee::query()->create([
            'employee_number' => 'ODATA-PAGE-'.$index,
            'first_name' => 'Page',
            'last_name' => (string) $index,
            'full_name' => 'Page '.$index,
            'status' => EmployeeStatus::Active->value,
        ]);
    }

    [, $token] = orgDataApplication();

    $response = $this->withToken($token)
        ->getJson('/api/v1/employees?per_page=2')
        ->assertOk()
        ->assertJsonPath('meta.per_page', 2)
        ->assertJsonPath('meta.current_page', 1);

    expect($response->json('data'))->toHaveCount(2)
        ->and($response->json('meta.total'))->toBe(5);
});

it('caps per_page so a caller cannot request the whole table', function (): void {
    [, $token] = orgDataApplication();

    $this->withToken($token)
        ->getJson('/api/v1/employees?per_page=5000')
        ->assertOk()
        ->assertJsonPath('meta.per_page', 100);
});

it('filters organizations by organization_code', function (): void {
    $type = OrganizationType::query()->create(['code' => 'OTHER-TYPE', 'name_en' => 'Other']);
    Organization::query()->create([
        'organization_type_id' => $type->id,
        'code' => 'OTHER-ORG',
        'name_en' => 'Other Organization',
        'status' => 'active',
    ]);

    [, $token] = orgDataApplication();

    $response = $this->withToken($token)
        ->getJson('/api/v1/organizations/directory?organization_code=ODATA-ORG')
        ->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.code'))->toBe('ODATA-ORG');
});

it('filters employees by employee_number', function (): void {
    Employee::query()->create([
        'employee_number' => 'ODATA-EMP-2',
        'first_name' => 'Other',
        'last_name' => 'Person',
        'full_name' => 'Other Person',
        'status' => EmployeeStatus::Active->value,
    ]);

    [, $token] = orgDataApplication();

    $response = $this->withToken($token)
        ->getJson('/api/v1/employees?employee_number=ODATA-EMP-1')
        ->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.employee_number'))->toBe('ODATA-EMP-1');
});

it('filters employees by organization_code through the current assignment', function (): void {
    Employee::query()->create([
        'employee_number' => 'ODATA-EMP-3',
        'first_name' => 'Unassigned',
        'last_name' => 'Person',
        'full_name' => 'Unassigned Person',
        'status' => EmployeeStatus::Active->value,
    ]);

    [, $token] = orgDataApplication();

    $response = $this->withToken($token)
        ->getJson('/api/v1/employees?organization_code=ODATA-ORG')
        ->assertOk();

    // Only the assigned employee belongs to the organization.
    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.employee_number'))->toBe('ODATA-EMP-1');
});

it('filters positions by position_code', function (): void {
    Position::query()->create([
        'organization_id' => $this->org->id,
        'organization_unit_id' => $this->unit->id,
        'job_position_code' => 'ODATA-P9',
        'title_en' => 'Another Post',
        'is_active' => true,
    ]);

    [, $token] = orgDataApplication();

    $response = $this->withToken($token)
        ->getJson('/api/v1/positions?position_code=ODATA-P1')
        ->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.code'))->toBe('ODATA-P1');
});

it('filters by updated_after', function (): void {
    [, $token] = orgDataApplication();

    // Nothing has been touched since tomorrow, so the page must be empty.
    $this->withToken($token)
        ->getJson('/api/v1/organizations/directory?updated_after='.urlencode(now()->addDay()->toIso8601String()))
        ->assertOk()
        ->assertJsonPath('meta.total', 0);

    $this->withToken($token)
        ->getJson('/api/v1/organizations/directory?updated_after='.urlencode(now()->subDay()->toIso8601String()))
        ->assertOk()
        ->assertJsonPath('meta.total', 1);
});

it('still filters when the timezone offset arrives unencoded', function (): void {
    [, $token] = orgDataApplication();

    // A caller that does not percent-encode `+03:00` sends a value that
    // arrives with a space where the plus was. It must still filter, not
    // silently return the whole table.
    $this->withToken($token)
        ->getJson('/api/v1/organizations/directory?updated_after='.now()->addDay()->toIso8601String())
        ->assertOk()
        ->assertJsonPath('meta.total', 0);
});

it('rejects an unparseable updated_after instead of ignoring it', function (): void {
    [, $token] = orgDataApplication();

    $this->withToken($token)
        ->getJson('/api/v1/organizations/directory?updated_after=not-a-date')
        ->assertStatus(422);
});

// ── Nested reads ─────────────────────────────────────────────────────────

it('lists units, positions and employees for an organization', function (): void {
    [, $token] = orgDataApplication();

    $this->withToken($token)
        ->getJson("/api/v1/organizations/{$this->org->id}/units")
        ->assertOk()
        ->assertJsonPath('data.0.code', 'ODATA-U1');

    $this->withToken($token)
        ->getJson("/api/v1/organizations/{$this->org->id}/positions")
        ->assertOk()
        ->assertJsonPath('data.0.code', 'ODATA-P1');

    $this->withToken($token)
        ->getJson("/api/v1/organizations/{$this->org->id}/employees")
        ->assertOk()
        ->assertJsonPath('data.0.employee_number', 'ODATA-EMP-1');
});

it('lists positions and employees for a unit', function (): void {
    [, $token] = orgDataApplication();

    $this->withToken($token)
        ->getJson("/api/v1/organization-units/{$this->unit->id}/positions")
        ->assertOk()
        ->assertJsonPath('data.0.code', 'ODATA-P1');

    $this->withToken($token)
        ->getJson("/api/v1/organization-units/{$this->unit->id}/employees")
        ->assertOk()
        ->assertJsonPath('data.0.employee_number', 'ODATA-EMP-1');
});

it('returns the current assignment for an employee', function (): void {
    [, $token] = orgDataApplication();

    $this->withToken($token)
        ->getJson("/api/v1/employees/{$this->employee->id}/assignment")
        ->assertOk()
        ->assertJsonPath('data.organization_id', $this->org->id)
        ->assertJsonPath('data.organization_unit_id', $this->unit->id)
        ->assertJsonPath('data.position_id', $this->position->id)
        ->assertJsonPath('data.is_current', true);
});

it('returns 404 when an employee has no current assignment', function (): void {
    $unassigned = Employee::query()->create([
        'employee_number' => 'ODATA-EMP-NA',
        'first_name' => 'No',
        'last_name' => 'Assignment',
        'full_name' => 'No Assignment',
        'status' => EmployeeStatus::Active->value,
    ]);

    [, $token] = orgDataApplication();

    $this->withToken($token)
        ->getJson("/api/v1/employees/{$unassigned->id}/assignment")
        ->assertNotFound()
        ->assertJsonPath('error_code', 'assignment_not_found');
});
