<?php

declare(strict_types=1);

use App\Enums\HierarchyVersionStatus;
use App\Enums\OrganizationRelationshipType;
use App\Enums\OrganizationStatus;
use App\Models\HierarchyVersion;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\OrganizationUnitType;
use App\Models\User;
use App\Services\Hierarchy\HierarchyVersionResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

// ── Helpers ───────────────────────────────────────────────────────────────────

beforeEach(function (): void {
    foreach ([
        'organization-units.viewAny',
        'organization-units.create',
        'organization-units.update',
        'organization-units.delete',
        'hierarchy-versions.viewAny',
        'hierarchy-versions.view',
    ] as $perm) {
        Permission::findOrCreate($perm, 'web');
    }

    Role::findOrCreate('Super Admin', 'web')->givePermissionTo(Permission::all());

    $this->orgType = OrganizationType::query()->create([
        'code' => 'bureau-rv',
        'name_en' => 'Bureau',
        'name_am' => 'ቢሮ',
    ]);

    $this->unitType = OrganizationUnitType::query()->create([
        'code' => 'dir-rv',
        'name_en' => 'Directorate',
        'name_am' => 'ዳይሬክቶሬት',
        'is_active' => true,
    ]);
});

function resolverAdmin(): User
{
    $user = User::factory()->create();
    $user->assignRole('Super Admin');

    return $user;
}

function resolverVersion(HierarchyVersionStatus $status, array $extra = []): HierarchyVersion
{
    return HierarchyVersion::query()->create(array_merge([
        'version_name' => 'rv-test-'.str()->lower(str()->uuid()),
        'status' => $status,
        'effective_from' => now()->toDateString(),
    ], $extra));
}

function resolverOrg(OrganizationType $type, string $code, string $name): Organization
{
    return Organization::query()->create([
        'organization_type_id' => $type->id,
        'code' => $code,
        'name_en' => $name,
        'status' => OrganizationStatus::Active,
        'effective_from' => now()->toDateString(),
    ]);
}

function resolverEdge(HierarchyVersion $version, Organization $parent, Organization $child): void
{
    $version->edges()->create([
        'parent_organization_id' => $parent->id,
        'child_organization_id' => $child->id,
        'relationship_type' => OrganizationRelationshipType::ReportsTo,
        'effective_from' => now()->toDateString(),
    ]);
}

// ── Tests ─────────────────────────────────────────────────────────────────────

test('resolver returns published version when available', function (): void {
    $draft = resolverVersion(HierarchyVersionStatus::Draft);
    $published = resolverVersion(HierarchyVersionStatus::Published);

    $resolver = app(HierarchyVersionResolver::class);
    $result = $resolver->resolveDefault();

    expect($result)->not->toBeNull();
    expect($result->id)->toBe($published->id);
    expect($result->status)->toBe(HierarchyVersionStatus::Published);
});

test('resolver falls back to latest draft when no published version exists', function (): void {
    $older = resolverVersion(HierarchyVersionStatus::Draft);
    // Force older's updated_at into the past so the resolver picks the newer one
    DB::table('hierarchy_versions')
        ->where('id', $older->id)
        ->update(['updated_at' => now()->subHour()]);

    $newer = resolverVersion(HierarchyVersionStatus::Draft);

    $resolver = app(HierarchyVersionResolver::class);
    $result = $resolver->resolveDefault();

    expect($result)->not->toBeNull();
    expect($result->status)->toBe(HierarchyVersionStatus::Draft);
    expect($result->id)->toBe($newer->id);
});

test('resolver returns null when no version exists at all', function (): void {
    $resolver = app(HierarchyVersionResolver::class);
    $result = $resolver->resolveDefault();

    expect($result)->toBeNull();
});

test('resolver uses explicitly requested version_id over defaults', function (): void {
    $published = resolverVersion(HierarchyVersionStatus::Published);
    $draft = resolverVersion(HierarchyVersionStatus::Draft);

    $request = Request::create('/organization-units', 'GET', [
        'hierarchy_version_id' => $draft->id,
    ]);

    $resolver = app(HierarchyVersionResolver::class);
    $result = $resolver->resolveForRequest($request);

    expect($result)->not->toBeNull();
    expect($result->id)->toBe($draft->id);
});

test('resolver falls through to default when requested version_id does not exist', function (): void {
    $published = resolverVersion(HierarchyVersionStatus::Published);

    $request = Request::create('/organization-units', 'GET', [
        'hierarchy_version_id' => '00000000-0000-0000-0000-000000000000',
    ]);

    $resolver = app(HierarchyVersionResolver::class);
    $result = $resolver->resolveForRequest($request);

    // Falls back to published since the requested ID was invalid
    expect($result)->not->toBeNull();
    expect($result->id)->toBe($published->id);
});

test('organization units index uses draft version when no published version exists', function (): void {
    $user = resolverAdmin();

    $draft = resolverVersion(HierarchyVersionStatus::Draft);
    $parent = resolverOrg($this->orgType, 'ORG-RV-P', 'Parent RV');
    $child = resolverOrg($this->orgType, 'ORG-RV-C', 'Child RV');
    resolverEdge($draft, $parent, $child);

    $this->actingAs($user)
        ->get(route('organization-units.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('OrganizationUnits/Index')
            ->where('usingFlatFallback', false)
            ->where('usingDraftFallback', true)
            ->where('hasPublishedHierarchy', false)
        );
});

test('organization units index does not fall back to flat list when draft exists', function (): void {
    $user = resolverAdmin();

    resolverVersion(HierarchyVersionStatus::Draft);

    $this->actingAs($user)
        ->get(route('organization-units.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('OrganizationUnits/Index')
            ->where('usingFlatFallback', false)
            ->where('usingDraftFallback', true)
        );
});

test('organization units index uses flat fallback only when no version exists at all', function (): void {
    $user = resolverAdmin();

    $this->actingAs($user)
        ->get(route('organization-units.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('OrganizationUnits/Index')
            ->where('usingFlatFallback', true)
            ->where('usingDraftFallback', false)
            ->where('hasPublishedHierarchy', false)
        );
});

test('organization units index uses published version when available', function (): void {
    $user = resolverAdmin();

    resolverVersion(HierarchyVersionStatus::Draft);
    resolverVersion(HierarchyVersionStatus::Published);

    $this->actingAs($user)
        ->get(route('organization-units.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('OrganizationUnits/Index')
            ->where('hasPublishedHierarchy', true)
            ->where('usingDraftFallback', false)
            ->where('usingFlatFallback', false)
        );
});

test('organization units index passes availableVersions to frontend', function (): void {
    $user = resolverAdmin();

    resolverVersion(HierarchyVersionStatus::Draft);
    resolverVersion(HierarchyVersionStatus::Published);
    resolverVersion(HierarchyVersionStatus::Archived); // should be excluded

    $this->actingAs($user)
        ->get(route('organization-units.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('OrganizationUnits/Index')
            ->has('availableVersions', 2)
        );
});

test('EN translation files contain new draft hierarchy keys', function (): void {
    $enTs = file_get_contents(resource_path('js/i18n/en/organizationUnits.ts'));
    $enHvTs = file_get_contents(resource_path('js/i18n/en/hierarchyVersions.ts'));
    $enPhp = file_get_contents(lang_path('en/hierarchy-versions.php'));

    expect($enTs)->toContain('showingDraftHierarchy');
    expect($enTs)->toContain('noHierarchyVersionFoundFlatList');
    expect($enHvTs)->toContain('noPublishedVersionUsingDraft');
    expect($enHvTs)->toContain('selectedHierarchyVersion');
    expect($enPhp)->toContain('no_published_version_using_draft');
    expect($enPhp)->toContain('selected_hierarchy_version');
    expect($enPhp)->toContain('no_hierarchy_version_found_flat_list');
});

test('AM translation files contain new draft hierarchy keys', function (): void {
    $amTs = file_get_contents(resource_path('js/i18n/am/organizationUnits.ts'));
    $amHvTs = file_get_contents(resource_path('js/i18n/am/hierarchyVersions.ts'));
    $amPhp = file_get_contents(lang_path('am/hierarchy-versions.php'));

    expect($amTs)->toContain('showingDraftHierarchy');
    expect($amTs)->toContain('noHierarchyVersionFoundFlatList');
    expect($amHvTs)->toContain('noPublishedVersionUsingDraft');
    expect($amHvTs)->toContain('selectedHierarchyVersion');
    expect($amPhp)->toContain('no_published_version_using_draft');
    expect($amPhp)->toContain('selected_hierarchy_version');
    // Verify no encoding corruption
    expect($amPhp)->not->toContain('Ã¡');
    expect($amTs)->not->toContain('Ã¡');
});
