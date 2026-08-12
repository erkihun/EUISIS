<?php

declare(strict_types=1);

use App\Enums\HierarchyVersionStatus;
use App\Models\HierarchyVersion;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    foreach (['organizations.view', 'organizations.manage'] as $perm) {
        Permission::findOrCreate($perm, 'web');
    }

    Role::findOrCreate('Super Admin', 'web')->givePermissionTo(['organizations.view', 'organizations.manage']);
    Role::findOrCreate('Viewer', 'web')->givePermissionTo(['organizations.view']);
});

/**
 * Verify the Organizations index page loads successfully with hierarchy versions
 * that have approval_date set. The page must return 200 even when date fields
 * contain ISO datetime strings (e.g. "2026-06-07T00:00:00.000000Z") — the
 * frontend LocalizedDateDisplay component handles the conversion via formatDateDisplay.
 */
test('organizations index loads successfully with hierarchy version approval dates', function (): void {
    $type = OrganizationType::query()->create(['code' => 'DEPT', 'name_en' => 'Department']);

    Organization::query()->create([
        'organization_type_id' => $type->id,
        'code' => 'ORG-DATE-TEST',
        'name_en' => 'Date Test Org',
        'status' => 'active',
        'effective_from' => now()->toDateString(),
    ]);

    // Create a hierarchy version so the approval_date field is populated
    HierarchyVersion::query()->create([
        'version_name' => 'v1.0',
        'status' => HierarchyVersionStatus::Published,
        'approval_date' => now(),
        'effective_from' => now()->toDateString(),
    ]);

    $user = User::factory()->create();
    $user->assignRole('Super Admin');

    $this->actingAs($user)
        ->get(route('organizations.index'))
        ->assertOk();
});

/**
 * Ensure the Employees index response is paginated (returns paginated props).
 */
test('employees index returns paginated employee data', function (): void {
    $user = User::factory()->create();
    $user->assignRole('Super Admin');

    $response = $this->actingAs($user)
        ->get(route('employees.index'))
        ->assertOk();

    // The Inertia response JSON should contain employees_pagination prop
    $content = $response->getContent();
    expect($content)->toContain('employees_pagination');
});
