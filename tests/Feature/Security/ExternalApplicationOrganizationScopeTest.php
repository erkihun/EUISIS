<?php

declare(strict_types=1);

use App\Enums\AssignmentStatus;
use App\Enums\EmployeeStatus;
use App\Models\ApiEndpointDefinition;
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
 * Phase-2: organization isolation on the integration API (SEC-010).
 *
 * An external application is gated on status, IP, endpoint assignment, scope
 * and rate limit — but nothing binds it to an organization. These tests state
 * the observed behaviour rather than the desired one, so the decision recorded
 * in docs/security-assessment-phase2.md is backed by evidence.
 */
beforeEach(function (): void {
    app(ApiEndpointCatalogService::class)->sync();

    $type = OrganizationType::query()->create(['code' => 'SCOPE-TYPE', 'name_en' => 'Scope Type']);

    $this->orgA = scopeOrganization($type, 'SCOPE-A', 'Bureau A');
    $this->orgB = scopeOrganization($type, 'SCOPE-B', 'Bureau B');

    $this->employeeA = scopeEmployee($this->orgA, 'SCOPE-EMP-A');
    $this->employeeB = scopeEmployee($this->orgB, 'SCOPE-EMP-B');
});

function scopeOrganization(OrganizationType $type, string $code, string $name): Organization
{
    return Organization::query()->create([
        'organization_type_id' => $type->id,
        'code' => $code,
        'name_en' => $name,
        'status' => 'active',
    ]);
}

function scopeEmployee(Organization $organization, string $number): Employee
{
    $unit = OrganizationUnit::query()->create([
        'organization_id' => $organization->id,
        'code' => $number.'-U',
        'name_en' => 'Unit '.$number,
        'unit_type' => 'department',
        'status' => 'active',
    ]);

    $position = Position::query()->create([
        'organization_id' => $organization->id,
        'organization_unit_id' => $unit->id,
        'job_position_code' => $number.'-P',
        'title_en' => 'Officer '.$number,
        'is_active' => true,
    ]);

    $employee = Employee::query()->create([
        'employee_number' => $number,
        'first_name' => 'Test',
        'last_name' => 'Person',
        'full_name' => 'Test Person',
        'status' => EmployeeStatus::Active->value,
    ]);

    $assignment = EmployeeAssignment::query()->create([
        'employee_id' => $employee->id,
        'organization_id' => $organization->id,
        'organization_unit_id' => $unit->id,
        'position_id' => $position->id,
        'is_current' => true,
        'assignment_status' => AssignmentStatus::Active->value,
        'effective_from' => now()->toDateString(),
    ]);

    $employee->forceFill(['current_assignment_id' => $assignment->id])->save();

    return $employee;
}

/** @return array{0: ExternalApplication, 1: string} */
function scopedApplication(): array
{
    $scopes = ['organizations.read', 'employees.basic_read', 'organization_structure.read', 'reports.read_limited'];

    $application = ExternalApplication::query()->create([
        'name' => 'Bureau A Integration',
        'code' => 'SCOPED-'.Str::random(6),
        'status' => 'active',
        'allowed_scopes' => $scopes,
        'rate_limit_per_minute' => 120,
    ]);

    $ids = ApiEndpointDefinition::query()->whereIn('required_scope', $scopes)->pluck('id')->all();

    $application->endpoints()->sync(
        collect($ids)->mapWithKeys(fn (string $id): array => [$id => [
            'id' => (string) Str::uuid(),
            'is_enabled' => true,
        ]])->all()
    );

    return [$application, $application->createToken('phase2', $application->grantableScopes())->plainTextToken];
}

/**
 * OBSERVED BEHAVIOUR, recorded as evidence for SEC-010.
 *
 * The directory endpoints are global by design: there is no organization
 * column on `external_applications` and both `index` and `show` behave the
 * same way, so this is not an inconsistency that could be exploited as a
 * BOLA. It is the blast radius of granting `employees.basic_read` at all.
 *
 * If organization binding is introduced, this test must be inverted to assert
 * 403 ORGANIZATION_SCOPE_DENIED.
 */
test('SEC-010 evidence: a scoped integration can read every organization', function (): void {
    [, $token] = scopedApplication();

    $response = $this->withToken($token)->getJson('/api/v1/organizations')->assertOk();

    // Both bureaus are returned to an application that serves only one.
    $codes = collect($response->json('data'))->pluck('code');

    expect($codes)->toContain('SCOPE-A')->toContain('SCOPE-B');
});

test('SEC-010 evidence: any employee is readable by id regardless of organization', function (): void {
    [, $token] = scopedApplication();

    $this->withToken($token)
        ->getJson('/api/v1/employees/'.$this->employeeB->id)
        ->assertOk()
        ->assertJsonPath('data.employee_number', 'SCOPE-EMP-B');
});

// ── Controls that DO hold — regression guards ────────────────────────────

test('an unauthenticated caller is refused', function (string $uri): void {
    $this->getJson($uri)->assertUnauthorized();
})->with([
    '/api/v1/organizations',
    '/api/v1/employees',
]);

test('a suspended application is refused even with a valid token', function (): void {
    [$application, $token] = scopedApplication();

    $application->update(['status' => 'suspended']);

    $this->withToken($token)->getJson('/api/v1/organizations')->assertForbidden();
});

test('an application without the required scope is refused', function (): void {
    [$application] = scopedApplication();

    // A token that carries only one unrelated scope.
    $token = $application->createToken('narrow', ['organizations.read'])->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/employees')->assertForbidden();
});

test('an application is refused an endpoint it was never assigned', function (): void {
    $application = ExternalApplication::query()->create([
        'name' => 'No Endpoints',
        'code' => 'NOEP-'.Str::random(6),
        'status' => 'active',
        'allowed_scopes' => ['employees.basic_read'],
        'rate_limit_per_minute' => 60,
    ]);

    $token = $application->createToken('phase2', $application->grantableScopes())->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/employees')->assertForbidden();
});

/* Excessive data exposure: the directory must never carry sensitive PII. */
test('the employee API never exposes sensitive fields', function (): void {
    [, $token] = scopedApplication();

    $body = $this->withToken($token)->getJson('/api/v1/employees/'.$this->employeeA->id)->json();
    $encoded = json_encode($body);

    foreach (['national_id', 'national_id_hash', 'password', 'remember_token', 'two_factor'] as $forbidden) {
        expect($encoded)->not->toContain($forbidden);
    }
});
