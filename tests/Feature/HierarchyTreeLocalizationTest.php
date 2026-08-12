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
use App\Services\Hierarchy\HierarchyTreeService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

// ── Shared helpers ────────────────────────────────────────────────────────────

afterEach(function (): void {
    // Reset locale after each test so locale changes don't bleed into other test suites
    app()->setLocale(config('app.locale', 'en'));
});

beforeEach(function (): void {
    foreach ([
        'hierarchy-versions.viewAny',
        'hierarchy-versions.view',
        'organization-edges.view',
    ] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    Role::findOrCreate('Super Admin', 'web')->givePermissionTo(Permission::all());

    $this->orgType = OrganizationType::query()->create([
        'code' => 'bureau-loc',
        'name_en' => 'Bureau',
        'name_am' => 'ቢሮ',
    ]);

    $this->unitType = OrganizationUnitType::query()->create([
        'code' => 'dir-loc',
        'name_en' => 'Directorate',
        'name_am' => 'ዳይሬክቶሬት',
        'is_active' => true,
    ]);
});

function locTestVersion(): HierarchyVersion
{
    return HierarchyVersion::query()->create([
        'version_name' => 'loc-test-'.str()->lower(str()->uuid()),
        'status' => HierarchyVersionStatus::Draft,
        'effective_from' => now()->toDateString(),
    ]);
}

function locTestOrg(OrganizationType $type, string $code, string $nameEn, ?string $nameAm = null): Organization
{
    return Organization::query()->create([
        'organization_type_id' => $type->id,
        'code' => $code,
        'name_en' => $nameEn,
        'name_am' => $nameAm,
        'status' => OrganizationStatus::Active,
        'effective_from' => now()->toDateString(),
    ]);
}

function locTestUnit(Organization $org, OrganizationUnitType $type, string $code, string $nameEn, ?string $nameAm = null, ?OrganizationUnit $parent = null): OrganizationUnit
{
    return OrganizationUnit::query()->create([
        'organization_id' => $org->id,
        'organization_unit_type_id' => $type->id,
        'parent_unit_id' => $parent?->id,
        'unit_type' => $type->code,
        'code' => $code,
        'name_en' => $nameEn,
        'name_am' => $nameAm,
        'status' => OrganizationUnitStatus::Active,
    ]);
}

function locTestEdge(HierarchyVersion $version, Organization $parent, Organization $child): void
{
    $version->edges()->create([
        'parent_organization_id' => $parent->id,
        'child_organization_id' => $child->id,
        'relationship_type' => OrganizationRelationshipType::ReportsTo,
        'effective_from' => now()->toDateString(),
    ]);
}

// ── Tests ─────────────────────────────────────────────────────────────────────

test('tree node label is Amharic when locale is am and name_am exists', function (): void {
    app()->setLocale('am');

    $version = locTestVersion();
    $parent = locTestOrg($this->orgType, 'LOC-P', 'Parent Bureau', 'ዋና ቢሮ');
    $child = locTestOrg($this->orgType, 'LOC-C', 'Child Bureau', 'ንዑስ ቢሮ');
    locTestEdge($version, $parent, $child);

    $tree = app(HierarchyTreeService::class)->buildFullTree($version);

    expect($tree[0]['label'])->toBe('ዋና ቢሮ');
    expect($tree[0]['children'][0]['label'])->toBe('ንዑስ ቢሮ');
});

test('tree node label is English when locale is en', function (): void {
    app()->setLocale('en');

    $version = locTestVersion();
    $parent = locTestOrg($this->orgType, 'LOC-E1', 'English Bureau', 'ቢሮ');
    $child = locTestOrg($this->orgType, 'LOC-E2', 'Child Bureau', 'ልጅ ቢሮ');
    locTestEdge($version, $parent, $child);

    $tree = app(HierarchyTreeService::class)->buildFullTree($version);

    expect($tree[0]['label'])->toBe('English Bureau');
    expect($tree[0]['children'][0]['label'])->toBe('Child Bureau');
});

test('tree node label falls back to English when name_am is null and locale is am', function (): void {
    app()->setLocale('am');

    $version = locTestVersion();
    $parent = locTestOrg($this->orgType, 'LOC-FB1', 'Fallback Bureau', null);
    $child = locTestOrg($this->orgType, 'LOC-FB2', 'Child Fallback', null);
    locTestEdge($version, $parent, $child);

    $tree = app(HierarchyTreeService::class)->buildFullTree($version);

    expect($tree[0]['label'])->toBe('Fallback Bureau');
    expect($tree[0]['children'][0]['label'])->toBe('Child Fallback');
});

test('tree node type label is Amharic for organization type when locale is am', function (): void {
    app()->setLocale('am');

    $version = locTestVersion();
    $parent = locTestOrg($this->orgType, 'LOC-OT1', 'Typed Bureau', 'ቢሮ');
    $child = locTestOrg($this->orgType, 'LOC-OT2', 'Typed Child', 'ልጅ');
    locTestEdge($version, $parent, $child);

    $tree = app(HierarchyTreeService::class)->buildFullTree($version);

    // orgType has name_en='Bureau', name_am='ቢሮ'
    expect($tree[0]['node_type_label'])->toBe('ቢሮ');
});

test('tree node type label is Amharic for organization unit type when locale is am', function (): void {
    app()->setLocale('am');

    $version = locTestVersion();
    $org = locTestOrg($this->orgType, 'LOC-UT1', 'Bureau For Unit', 'ቢሮ');
    $child = locTestOrg($this->orgType, 'LOC-UT2', 'Child Bureau', 'ልጅ');
    locTestEdge($version, $org, $child);

    locTestUnit($org, $this->unitType, 'DIR-LOC', 'HR Directorate', 'ሰው ሃብት ዳይሬክቶሬት');

    $tree = app(HierarchyTreeService::class)->buildFullTree($version);

    $unitNode = collect($tree[0]['children'])->filter(fn ($n) => $n['type'] === 'organization_unit')->first();

    expect($unitNode)->not->toBeNull();
    // unitType has name_en='Directorate', name_am='ዳይሬክቶሬት'
    expect($unitNode['node_type_label'])->toBe('ዳይሬክቶሬት');
    expect($unitNode['label'])->toBe('ሰው ሃብት ዳይሬክቶሬት');
});

test('tree node status_label is translated and not the raw enum value', function (): void {
    app()->setLocale('am');

    $version = locTestVersion();
    $parent = locTestOrg($this->orgType, 'LOC-SL1', 'Status Bureau', 'ቢሮ');
    $child = locTestOrg($this->orgType, 'LOC-SL2', 'Status Child', 'ልጅ');
    locTestEdge($version, $parent, $child);

    $tree = app(HierarchyTreeService::class)->buildFullTree($version);

    // status = 'active', translated to 'ንቁ' in am
    expect($tree[0]['status'])->toBe('active');
    expect($tree[0]['status_label'])->toBe('ንቁ');
});

test('EN PHP translation file has organization and organization_unit keys', function (): void {
    $content = file_get_contents(lang_path('en/hierarchy-versions.php'));

    expect($content)->toContain("'organization'");
    expect($content)->toContain("'organization_unit'");
    expect($content)->toContain("'draft'");
    expect($content)->toContain("'published'");
    expect($content)->toContain("'active'");
    expect($content)->toContain("'inactive'");
});

test('AM PHP translation file has organization and organization_unit keys', function (): void {
    $content = file_get_contents(lang_path('am/hierarchy-versions.php'));

    expect($content)->toContain("'organization'");
    expect($content)->toContain("'organization_unit'");
    expect($content)->toContain('ተቋም');
    expect($content)->toContain('ረቂቅ');
    expect($content)->toContain('ንቁ');
});

test('organization tree preview key exists in EN and AM TypeScript i18n files', function (): void {
    $enTs = file_get_contents(resource_path('js/i18n/en/organizationUnits.ts'));
    $amTs = file_get_contents(resource_path('js/i18n/am/organizationUnits.ts'));

    expect($enTs)->toContain('organizationTreePreview');
    expect($amTs)->toContain('organizationTreePreview');
    expect($amTs)->toContain('የተቋም አደረጃጀት ቅድመ-ዕይታ');
});
