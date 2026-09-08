<?php

declare(strict_types=1);

use App\Models\ApiEndpointDefinition;
use App\Models\ExternalApplication;
use App\Models\User;
use App\Services\ApiEndpointCatalogService;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * API Management: assigning the organization-to-employee endpoints to an
 * external application through the New/Edit Application form.
 */
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

    app(ApiEndpointCatalogService::class)->sync();

    $this->manager = User::factory()->create();
    $this->manager->assignRole('API Manager');
    $this->manager = $this->manager->fresh();
});

/** Catalog rows for the organization-to-employee endpoints. */
function organizationDataEndpoints(): Collection
{
    return ApiEndpointDefinition::query()
        ->whereIn('required_scope', [
            'organizations.read', 'organization_units.read', 'positions.read',
            'employees.basic_read', 'employee_assignments.read', 'organization_structure.read',
        ])
        ->get();
}

it('offers the organization-to-employee endpoints for assignment', function (): void {
    $expected = organizationDataEndpoints()->pluck('uri')->all();

    $this->actingAs($this->manager)
        ->get(route('api-management.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('assignableEndpoints', fn ($endpoints): bool => collect($endpoints)
                ->pluck('uri')
                ->intersect($expected)
                ->count() === count($expected))
        );

    expect($expected)->toHaveCount(15);
});

it('groups them as organization, employee and structure data APIs in the picker', function (): void {
    $this->actingAs($this->manager)
        ->get(route('api-management.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('assignableEndpoints', function ($endpoints): bool {
                $groups = collect($endpoints)->pluck('group')->unique();

                return $groups->contains('organization_data_api')
                    && $groups->contains('employee_data_api')
                    && $groups->contains('organization_structure_api');
            }));
});

it('saves the selected endpoints when creating an application', function (): void {
    $endpoints = organizationDataEndpoints();

    $this->actingAs($this->manager)
        ->post(route('api-management.store'), [
            'name' => 'Structure Consumer',
            'code' => 'STRUCT-1',
            'status' => 'active',
            'allowed_scopes' => [],
            'rate_limit_per_minute' => 120,
            'endpoint_ids' => $endpoints->pluck('id')->all(),
        ])
        ->assertRedirect();

    $application = ExternalApplication::query()->where('code', 'STRUCT-1')->sole();

    expect($application->endpoints()->pluck('uri')->sort()->values()->all())
        ->toBe($endpoints->pluck('uri')->sort()->values()->all());
});

it('grants the scopes the selected endpoints require', function (): void {
    $structure = organizationDataEndpoints()
        ->firstWhere('uri', '/api/v1/organizations/{organization}/structure');

    // Deliberately submitting no scopes: selecting the endpoint must imply the
    // scope, otherwise the application could never call what it was assigned.
    $this->actingAs($this->manager)
        ->post(route('api-management.store'), [
            'name' => 'Structure Only',
            'code' => 'STRUCT-2',
            'status' => 'active',
            'allowed_scopes' => [],
            'rate_limit_per_minute' => 60,
            'endpoint_ids' => [$structure->id],
        ])
        ->assertRedirect();

    expect(ExternalApplication::query()->where('code', 'STRUCT-2')->sole()->allowed_scopes)
        ->toContain('organization_structure.read');
});

it('updates endpoint assignments when editing an application', function (): void {
    $endpoints = organizationDataEndpoints();
    $employeeEndpoints = $endpoints->where('required_scope', 'employees.basic_read');

    $application = ExternalApplication::query()->create([
        'name' => 'Editable',
        'code' => 'STRUCT-3',
        'status' => 'active',
        'allowed_scopes' => [],
        'rate_limit_per_minute' => 60,
    ]);

    $this->actingAs($this->manager)
        ->patch(route('api-management.update', $application), [
            'name' => 'Editable',
            'code' => 'STRUCT-3',
            'status' => 'active',
            'allowed_scopes' => [],
            'rate_limit_per_minute' => 60,
            'endpoint_ids' => $employeeEndpoints->pluck('id')->all(),
        ])
        ->assertRedirect();

    expect($application->fresh()->endpoints()->pluck('uri')->sort()->values()->all())
        ->toBe($employeeEndpoints->pluck('uri')->sort()->values()->all())
        ->and($application->fresh()->allowed_scopes)->toContain('employees.basic_read');
});

it('shows the assigned organization and employee endpoints on the application page', function (): void {
    $endpoints = organizationDataEndpoints();

    $application = ExternalApplication::query()->create([
        'name' => 'Viewable',
        'code' => 'STRUCT-4',
        'status' => 'active',
        'allowed_scopes' => ['organizations.read'],
        'rate_limit_per_minute' => 60,
    ]);

    $application->endpoints()->sync(
        $endpoints->mapWithKeys(fn (ApiEndpointDefinition $endpoint): array => [$endpoint->id => [
            'id' => (string) Str::uuid(),
            'is_enabled' => true,
        ]])->all()
    );

    $this->actingAs($this->manager)
        ->get(route('api-management.show', $application))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('assignedEndpoints', $endpoints->count())
            ->where('assignedEndpoints', fn ($assigned): bool => collect($assigned)
                ->pluck('uri')
                ->contains('/api/v1/organizations/{organization}/structure'))
        );
});

it('refuses to assign an endpoint that is not assignable', function (): void {
    $hidden = organizationDataEndpoints()->firstWhere('uri', '/api/v1/employees');
    $hidden->update(['is_public_documented' => false]);

    // A crafted request must not be able to attach an endpoint the UI does not
    // offer, even though the id is real.
    $this->actingAs($this->manager)
        ->post(route('api-management.store'), [
            'name' => 'Sneaky',
            'code' => 'STRUCT-5',
            'status' => 'active',
            'allowed_scopes' => [],
            'rate_limit_per_minute' => 60,
            'endpoint_ids' => [$hidden->id],
        ])
        ->assertSessionHasErrors('endpoint_ids.0');
});
