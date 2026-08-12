<?php

declare(strict_types=1);

use App\Enums\HierarchyVersionStatus;
use App\Enums\OrganizationRelationshipType;
use App\Enums\OrganizationStatus;
use App\Enums\OrganizationUnitStatus;
use App\Models\HierarchyVersion;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\OrganizationUnitType;
use App\Models\User;
use App\Services\Hierarchy\HierarchyTreeService;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

// ── Shared helpers ────────────────────────────────────────────────────────────

beforeEach(function (): void {
    foreach ([
        'hierarchy-versions.viewAny',
        'hierarchy-versions.view',
        'hierarchy-versions.create',
        'hierarchy-versions.update',
        'hierarchy-versions.archive',
        'hierarchy-versions.publish',
        'hierarchy-versions.manageTree',
        'organization-edges.view',
        'organization-edges.create',
        'organization-edges.update',
        'organization-edges.remove',
    ] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    Role::findOrCreate('Super Admin', 'web')->givePermissionTo(Permission::all());

    $this->orgType = OrganizationType::query()->create([
        'code' => 'bureau',
        'name_en' => 'Bureau',
        'name_am' => 'ቢሮ',
    ]);

    $this->unitType = OrganizationUnitType::query()->create([
        'code' => 'directorate',
        'name_en' => 'Directorate',
        'name_am' => 'ዳይሬክቶሬት',
        'is_active' => true,
    ]);
});

function treeTestAdmin(): User
{
    $user = User::factory()->create();
    $user->assignRole('Super Admin');

    return $user;
}

function treeTestVersion(array $attrs = []): HierarchyVersion
{
    return HierarchyVersion::query()->create(array_merge([
        'version_name' => 'tree-test-'.str()->lower(str()->uuid()),
        'status' => HierarchyVersionStatus::Draft,
        'effective_from' => now()->toDateString(),
    ], $attrs));
}

function treeTestOrg(OrganizationType $type, string $code, string $name): Organization
{
    return Organization::query()->create([
        'organization_type_id' => $type->id,
        'code' => $code,
        'name_en' => $name,
        'status' => OrganizationStatus::Active,
        'effective_from' => now()->toDateString(),
    ]);
}

function treeTestUnit(Organization $org, OrganizationUnitType $type, string $code, string $name, ?OrganizationUnit $parent = null): OrganizationUnit
{
    return OrganizationUnit::query()->create([
        'organization_id' => $org->id,
        'organization_unit_type_id' => $type->id,
        'parent_unit_id' => $parent?->id,
        'unit_type' => $type->code,
        'code' => $code,
        'name_en' => $name,
        'status' => OrganizationUnitStatus::Active,
    ]);
}

function treeTestEdge(HierarchyVersion $version, Organization $parent, Organization $child): void
{
    $version->edges()->create([
        'parent_organization_id' => $parent->id,
        'child_organization_id' => $child->id,
        'relationship_type' => OrganizationRelationshipType::ReportsTo,
        'effective_from' => now()->toDateString(),
    ]);
}

// ── Tests ─────────────────────────────────────────────────────────────────────

test('hierarchy tree includes organization nodes', function (): void {
    $version = treeTestVersion();
    $parent = treeTestOrg($this->orgType, 'ORG-TT-P', 'Parent Bureau');
    $child = treeTestOrg($this->orgType, 'ORG-TT-C', 'Child Bureau');
    treeTestEdge($version, $parent, $child);

    $service = app(HierarchyTreeService::class);
    $tree = $service->buildFullTree($version);

    expect($tree)->toHaveCount(1);
    expect($tree[0]['type'])->toBe('organization');
    expect($tree[0]['name_en'])->toBe('Parent Bureau');
    expect($tree[0]['children'][0]['type'])->toBe('organization');
    expect($tree[0]['children'][0]['name_en'])->toBe('Child Bureau');
});

test('hierarchy tree includes organization units under correct organization', function (): void {
    $version = treeTestVersion();
    $org = treeTestOrg($this->orgType, 'ORG-TT-U', 'Bureau With Units');
    // Make it a root by being a parent with no parent itself
    $child = treeTestOrg($this->orgType, 'ORG-TT-U2', 'Child Bureau');
    treeTestEdge($version, $org, $child);

    treeTestUnit($org, $this->unitType, 'DIR-001', 'HR Directorate');
    treeTestUnit($org, $this->unitType, 'DIR-002', 'Finance Directorate');

    $service = app(HierarchyTreeService::class);
    $tree = $service->buildFullTree($version);

    // Root org should have child org + 2 root units = 3 children
    expect($tree[0]['children'])->toHaveCount(3);

    $unitChildren = collect($tree[0]['children'])->filter(fn ($n) => $n['type'] === 'organization_unit');
    expect($unitChildren)->toHaveCount(2);

    $unitNames = $unitChildren->pluck('name_en')->sort()->values()->all();
    expect($unitNames)->toBe(['Finance Directorate', 'HR Directorate']);
});

test('child organization units appear recursively', function (): void {
    $version = treeTestVersion();
    $org = treeTestOrg($this->orgType, 'ORG-TT-R', 'Bureau Root');
    $child = treeTestOrg($this->orgType, 'ORG-TT-R2', 'Sub Bureau');
    treeTestEdge($version, $org, $child);

    $parent = treeTestUnit($org, $this->unitType, 'DIR-P', 'Parent Directorate');
    treeTestUnit($org, $this->unitType, 'DIR-C', 'Child Directorate', $parent);

    $service = app(HierarchyTreeService::class);
    $tree = $service->buildFullTree($version);

    // Root org children: 1 org + 1 root unit
    $unitNodes = collect($tree[0]['children'])->filter(fn ($n) => $n['type'] === 'organization_unit');
    expect($unitNodes)->toHaveCount(1);

    $parentUnit = $unitNodes->first();
    expect($parentUnit['name_en'])->toBe('Parent Directorate');
    expect($parentUnit['children'])->toHaveCount(1);
    expect($parentUnit['children'][0]['type'])->toBe('organization_unit');
    expect($parentUnit['children'][0]['name_en'])->toBe('Child Directorate');
});

test('organization units do not appear when include_units is false', function (): void {
    $version = treeTestVersion();
    $org = treeTestOrg($this->orgType, 'ORG-TT-F', 'Bureau Filter');
    $child = treeTestOrg($this->orgType, 'ORG-TT-F2', 'Sub Bureau');
    treeTestEdge($version, $org, $child);

    treeTestUnit($org, $this->unitType, 'DIR-F', 'A Directorate');

    $service = app(HierarchyTreeService::class);
    $tree = $service->buildFullTree($version, null, ['include_units' => false]);

    // Only 1 child org, no unit nodes
    $unitChildren = collect($tree[0]['children'])->filter(fn ($n) => $n['type'] === 'organization_unit');
    expect($unitChildren)->toHaveCount(0);
});

test('functional reporting is NOT rendered as structural child by default', function (): void {
    $version = treeTestVersion();
    $org = treeTestOrg($this->orgType, 'ORG-TT-FR', 'Bureau FR');
    $child = treeTestOrg($this->orgType, 'ORG-TT-FR2', 'Sub Bureau');
    treeTestEdge($version, $org, $child);

    $unit = treeTestUnit($org, $this->unitType, 'DIR-FR', 'HR Unit');

    // Create a functional relationship (reports to another org)
    DB::table('organization_unit_relationships')->insert([
        'id' => str()->uuid(),
        'source_unit_id' => $unit->id,
        'target_type' => 'organization',
        'target_id' => $child->id,
        'relationship_type' => 'functional_reporting',
        'is_primary' => false,
        'status' => 'active',
        'effective_from' => now()->toDateString(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $service = app(HierarchyTreeService::class);
    $tree = $service->buildFullTree($version);

    // Unit should appear as a child of its org, not of the target org
    $unitChildren = collect($tree[0]['children'])->filter(fn ($n) => $n['type'] === 'organization_unit');
    expect($unitChildren)->toHaveCount(1);

    // The target child org's children should NOT include the unit
    $childOrgNode = collect($tree[0]['children'])->filter(fn ($n) => $n['type'] === 'organization')->first();
    expect($childOrgNode)->not->toBeNull();

    $functionalAppearances = collect($childOrgNode['children'])->filter(fn ($n) => $n['type'] === 'organization_unit');
    expect($functionalAppearances)->toHaveCount(0);
});

test('inactive units are hidden by default', function (): void {
    $version = treeTestVersion();
    $org = treeTestOrg($this->orgType, 'ORG-TT-I', 'Bureau Inactive');
    $child = treeTestOrg($this->orgType, 'ORG-TT-I2', 'Sub Bureau');
    treeTestEdge($version, $org, $child);

    treeTestUnit($org, $this->unitType, 'DIR-ACTIVE', 'Active Unit');
    OrganizationUnit::query()->create([
        'organization_id' => $org->id,
        'organization_unit_type_id' => $this->unitType->id,
        'unit_type' => $this->unitType->code,
        'code' => 'DIR-INACTIVE',
        'name_en' => 'Inactive Unit',
        'status' => OrganizationUnitStatus::Inactive,
    ]);

    $service = app(HierarchyTreeService::class);
    $tree = $service->buildFullTree($version);

    $unitChildren = collect($tree[0]['children'])->filter(fn ($n) => $n['type'] === 'organization_unit');
    expect($unitChildren)->toHaveCount(1);
    expect($unitChildren->first()['name_en'])->toBe('Active Unit');
});

test('include_inactive=true shows inactive units', function (): void {
    $version = treeTestVersion();
    $org = treeTestOrg($this->orgType, 'ORG-TT-IA', 'Bureau IA');
    $child = treeTestOrg($this->orgType, 'ORG-TT-IA2', 'Sub Bureau');
    treeTestEdge($version, $org, $child);

    treeTestUnit($org, $this->unitType, 'DIR-A2', 'Active Unit 2');
    OrganizationUnit::query()->create([
        'organization_id' => $org->id,
        'organization_unit_type_id' => $this->unitType->id,
        'unit_type' => $this->unitType->code,
        'code' => 'DIR-IN2',
        'name_en' => 'Inactive Unit 2',
        'status' => OrganizationUnitStatus::Inactive,
    ]);

    $service = app(HierarchyTreeService::class);
    $tree = $service->buildFullTree($version, null, ['include_inactive' => true]);

    $unitChildren = collect($tree[0]['children'])->filter(fn ($n) => $n['type'] === 'organization_unit');
    expect($unitChildren)->toHaveCount(2);
});

test('tree response returns correct unit_count on org node meta', function (): void {
    $version = treeTestVersion();
    $org = treeTestOrg($this->orgType, 'ORG-TT-C1', 'Bureau Count');
    $child = treeTestOrg($this->orgType, 'ORG-TT-C2', 'Sub Bureau');
    treeTestEdge($version, $org, $child);

    treeTestUnit($org, $this->unitType, 'DIR-C1', 'Unit One');
    treeTestUnit($org, $this->unitType, 'DIR-C2', 'Unit Two');

    $service = app(HierarchyTreeService::class);
    $tree = $service->buildFullTree($version);

    // organization_unit_count in meta should be 2 (root units only)
    expect($tree[0]['meta']['organization_unit_count'])->toBe(2);
});

test('tree HTTP endpoint includes organization units when include_units is true', function (): void {
    $user = treeTestAdmin();
    $version = treeTestVersion();
    $org = treeTestOrg($this->orgType, 'ORG-TT-H', 'Bureau HTTP');
    $child = treeTestOrg($this->orgType, 'ORG-TT-H2', 'Child HTTP');
    treeTestEdge($version, $org, $child);

    treeTestUnit($org, $this->unitType, 'DIR-H', 'HTTP Unit');

    $this->actingAs($user)
        ->get(route('hierarchy-versions.tree', ['hierarchyVersion' => $version->id, 'include_units' => 'true']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('HierarchyVersions/Tree')
            ->has('tree')
        );
});

test('tree node for organization_unit has correct type field', function (): void {
    // Pin to English so node_type_label is predictable regardless of APP_LOCALE
    $previousLocale = app()->getLocale();
    app()->setLocale('en');

    $version = treeTestVersion();
    $org = treeTestOrg($this->orgType, 'ORG-TT-TF', 'Bureau Type');
    $child = treeTestOrg($this->orgType, 'ORG-TT-TF2', 'Child Type');
    treeTestEdge($version, $org, $child);

    treeTestUnit($org, $this->unitType, 'DIR-TF', 'Type Field Unit');

    $service = app(HierarchyTreeService::class);
    $tree = $service->buildFullTree($version);

    app()->setLocale($previousLocale);

    $unitNode = collect($tree[0]['children'])->filter(fn ($n) => $n['type'] === 'organization_unit')->first();

    expect($unitNode)->not->toBeNull();
    expect($unitNode['type'])->toBe('organization_unit');
    expect($unitNode['node_type_label'])->toBe('Directorate');
    expect($unitNode['node_type_label_am'])->toBe('ዳይሬክቶሬት');
    expect($unitNode['can']['edit'])->toBeFalse();
    expect($unitNode['can']['remove'])->toBeFalse();
    expect($unitNode['can']['addChild'])->toBeFalse();
});

test('EN and AM translation files contain new organization unit tree keys', function (): void {
    $enTs = file_get_contents(resource_path('js/i18n/en/hierarchyVersions.ts'));
    $amTs = file_get_contents(resource_path('js/i18n/am/hierarchyVersions.ts'));
    $enPhp = file_get_contents(lang_path('en/hierarchy-versions.php'));
    $amPhp = file_get_contents(lang_path('am/hierarchy-versions.php'));

    // TypeScript EN
    expect($enTs)->toContain('organizationNode');
    expect($enTs)->toContain('organizationUnitNode');
    expect($enTs)->toContain('positionCount');
    expect($enTs)->toContain('employeeCount');
    expect($enTs)->toContain('unitCount');

    // TypeScript AM
    expect($amTs)->toContain('organizationNode');
    expect($amTs)->toContain('organizationUnitNode');
    expect($amTs)->toContain('ተቋም');
    expect($amTs)->toContain('ክፍል');

    // PHP EN
    expect($enPhp)->toContain('organization_node');
    expect($enPhp)->toContain('organization_unit_node');
    expect($enPhp)->toContain('position_count');
    expect($enPhp)->toContain('employee_count');

    // PHP AM
    expect($amPhp)->toContain('organization_node');
    expect($amPhp)->toContain('organization_unit_node');
    // No garbled encoding
    expect($amPhp)->not->toContain('Ã¡');
    expect($amTs)->not->toContain('Ã¡');
});

test('hierarchy tree view tree page receives tree prop', function (): void {
    $user = treeTestAdmin();
    $version = treeTestVersion();
    $org = treeTestOrg($this->orgType, 'ORG-TT-VP', 'Bureau View');
    $child = treeTestOrg($this->orgType, 'ORG-TT-VP2', 'Child View');
    treeTestEdge($version, $org, $child);

    $this->actingAs($user)
        ->get(route('hierarchy-versions.tree', $version))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('HierarchyVersions/Tree')
            ->has('tree')
            ->has('summary')
            ->has('filters')
        );
});

test('show page tree includes organization units in the tree prop', function (): void {
    $user = treeTestAdmin();
    $version = treeTestVersion();
    $org = treeTestOrg($this->orgType, 'ORG-TT-SP', 'Bureau Show');
    $child = treeTestOrg($this->orgType, 'ORG-TT-SP2', 'Child Show');
    treeTestEdge($version, $org, $child);

    treeTestUnit($org, $this->unitType, 'DIR-SP', 'Show Unit');

    $this->actingAs($user)
        ->get(route('hierarchy-versions.show', $version))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('HierarchyVersions/Show')
            ->has('tree')
        );
});
