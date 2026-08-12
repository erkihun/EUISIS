<?php

declare(strict_types=1);

use App\Models\OrganizationType;
use App\Models\User;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    foreach ([
        'organization-types.viewAny',
        'organization-types.view',
        'organization-types.create',
        'organization-types.update',
        'organization-types.delete',
        'organization-types.restore',
        'organization-types.viewDeleted',
    ] as $perm) {
        Permission::findOrCreate($perm, 'web');
    }

    Role::findOrCreate('OrgType Admin', 'web')->syncPermissions([
        'organization-types.viewAny',
        'organization-types.view',
        'organization-types.create',
        'organization-types.update',
        'organization-types.delete',
        'organization-types.restore',
        'organization-types.viewDeleted',
    ]);
});

function hierarchyAdminUser(): User
{
    $user = User::factory()->create();
    $user->assignRole('OrgType Admin');

    return $user;
}

function makeHierarchyOrgType(string $code = 'HTST', array $extra = []): OrganizationType
{
    return OrganizationType::query()->create(array_merge(['code' => $code, 'name_en' => "Type $code"], $extra));
}

// ── Model: parent_allowed_types casting ────────────────────────────────────

test('parent_allowed_types casts to array when stored as json', function (): void {
    $type = OrganizationType::query()->create([
        'code' => 'CAST_TEST',
        'name_en' => 'Cast Test',
        'parent_allowed_types' => ['CITY_ADMIN', 'BUREAU'],
    ]);

    expect($type->fresh()->parent_allowed_types)
        ->toBeArray()
        ->toContain('CITY_ADMIN')
        ->toContain('BUREAU');
});

test('parent_allowed_types stores empty array for root types', function (): void {
    $type = OrganizationType::query()->create([
        'code' => 'ROOT_CAST',
        'name_en' => 'Root Cast',
        'parent_allowed_types' => [],
    ]);

    expect($type->fresh()->parent_allowed_types)->toBeArray()->toBeEmpty();
});

test('parent_allowed_types is null when not set', function (): void {
    $type = OrganizationType::query()->create([
        'code' => 'NULL_PAT',
        'name_en' => 'Null PAT',
    ]);

    expect($type->fresh()->parent_allowed_types)->toBeNull();
});

// ── Model: allowsParentType logic ──────────────────────────────────────────

test('allowsParentType returns true when parent code is in allowed list', function (): void {
    $subCity = OrganizationType::query()->create([
        'code' => 'SUB_CITY_T',
        'name_en' => 'Sub City',
        'parent_allowed_types' => ['CITY_ADMIN_T'],
    ]);
    $cityAdmin = OrganizationType::query()->create([
        'code' => 'CITY_ADMIN_T',
        'name_en' => 'City Admin',
        'parent_allowed_types' => [],
    ]);

    expect($subCity->allowsParentType($cityAdmin))->toBeTrue();
});

test('allowsParentType returns false when parent code is not in allowed list', function (): void {
    $woreda = OrganizationType::query()->create([
        'code' => 'WOREDA_T',
        'name_en' => 'Woreda',
        'parent_allowed_types' => ['SUB_CITY_T2'],
    ]);
    $bureau = OrganizationType::query()->create([
        'code' => 'BUREAU_T',
        'name_en' => 'Bureau',
        'parent_allowed_types' => ['CITY_ADMIN_T2'],
    ]);

    expect($woreda->allowsParentType($bureau))->toBeFalse();
});

test('allowsParentType returns false for root type with empty parent_allowed_types', function (): void {
    $root = OrganizationType::query()->create([
        'code' => 'ROOT_T',
        'name_en' => 'Root',
        'parent_allowed_types' => [],
    ]);
    $any = OrganizationType::query()->create([
        'code' => 'ANY_T',
        'name_en' => 'Any',
    ]);

    expect($root->allowsParentType($any))->toBeFalse();
});

test('allowsParentType returns true when parent_allowed_types is null (no restriction)', function (): void {
    $independent = OrganizationType::query()->create([
        'code' => 'INDEP_T',
        'name_en' => 'Independent',
        'parent_allowed_types' => null,
    ]);
    $any = OrganizationType::query()->create([
        'code' => 'ANY2_T',
        'name_en' => 'Any',
    ]);

    expect($independent->allowsParentType($any))->toBeTrue();
});

// ── Model: scopeActive ─────────────────────────────────────────────────────

test('scopeActive only returns active organization types', function (): void {
    OrganizationType::query()->create(['code' => 'ACTIVE_T', 'name_en' => 'Active', 'is_active' => true]);
    OrganizationType::query()->create(['code' => 'INACTIVE_T', 'name_en' => 'Inactive', 'is_active' => false]);

    $codes = OrganizationType::active()->pluck('code')->all();

    expect($codes)->toContain('ACTIVE_T')->not->toContain('INACTIVE_T');
});

// ── Model: level_order integer cast ───────────────────────────────────────

test('level_order is cast to integer', function (): void {
    $type = OrganizationType::query()->create([
        'code' => 'LVL_T',
        'name_en' => 'Level Test',
        'level_order' => 3,
    ]);

    expect($type->fresh()->level_order)->toBe(3)->toBeInt();
});

// ── Validation: OrganizationTypeStoreRequest ───────────────────────────────

test('organization type can be created with level_order category and parent_allowed_types', function (): void {
    $user = hierarchyAdminUser();

    OrganizationType::query()->create(['code' => 'CITY_ADMIN_S', 'name_en' => 'City Admin Seed']);

    $this->actingAs($user)
        ->post(route('organization-types.store'), [
            'code' => 'WOREDA_NEW',
            'name_en' => 'Woreda Type',
            'level_order' => 3,
            'category' => 'geographic',
            'parent_allowed_types' => ['CITY_ADMIN_S'],
        ])
        ->assertRedirect(route('organization-types.index'));

    $created = OrganizationType::query()->where('name_en', 'Woreda Type')->firstOrFail();
    expect($created->level_order)->toBe(3)
        ->and($created->category)->toBe('geographic')
        ->and($created->parent_allowed_types)->toContain('CITY_ADMIN_S');
});

test('organization type store rejects non-existent parent code', function (): void {
    $this->actingAs(hierarchyAdminUser())
        ->post(route('organization-types.store'), [
            'name_en' => 'Bad Parent Type',
            'parent_allowed_types' => ['DOES_NOT_EXIST'],
        ])
        ->assertSessionHasErrors('parent_allowed_types.0');
});

test('organization type store rejects invalid category', function (): void {
    $this->actingAs(hierarchyAdminUser())
        ->post(route('organization-types.store'), [
            'name_en' => 'Bad Category Type',
            'category' => 'not_a_valid_category',
        ])
        ->assertSessionHasErrors('category');
});

// ── i18n: new keys exist ───────────────────────────────────────────────────

test('EN and AM organization-type translation keys for new fields exist', function (): void {
    expect(trans('organization-types.level_order', [], 'en'))->toBe('Level Order')
        ->and(trans('organization-types.category', [], 'en'))->toBe('Category')
        ->and(trans('organization-types.parent_allowed_types', [], 'en'))->toBe('Allowed Parent Types')
        ->and(trans('organization-types.level_order', [], 'am'))->toBe('የደረጃ ቅደም ተከተል')
        ->and(trans('organization-types.category', [], 'am'))->toBe('ምድብ')
        ->and(trans('organization-types.parent_allowed_types', [], 'am'))->toBe('የተፈቀዱ የበላይ ተቋም ዓይነቶች');
});

test('EN and AM TS i18n files contain new organization type keys', function (): void {
    $enTs = file_get_contents(resource_path('js/i18n/en/organizationTypes.ts'));
    $amTs = file_get_contents(resource_path('js/i18n/am/organizationTypes.ts'));

    expect($enTs)
        ->toContain('levelOrder')
        ->toContain('parentAllowedTypes')
        ->toContain('categoryRoot');

    expect($amTs)
        ->toContain('levelOrder')
        ->toContain('parentAllowedTypes')
        ->toContain('categoryRoot');
});

// ── Validation: organization-types index includes new fields ───────────────

test('organization type index page includes level_order category and parent_allowed_types props', function (): void {
    OrganizationType::query()->create([
        'code' => 'IDX_T',
        'name_en' => 'Index Test',
        'level_order' => 2,
        'category' => 'functional',
        'parent_allowed_types' => ['IDX_PARENT'],
    ]);

    $this->actingAs(hierarchyAdminUser())
        ->get(route('organization-types.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('OrganizationTypes/Index')
            ->has('types.0.level_order')
            ->has('types.0.category')
            ->has('types.0.parent_allowed_types')
        );
});

test('organization type create page receives allTypes prop', function (): void {
    $this->actingAs(hierarchyAdminUser())
        ->get(route('organization-types.create'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('OrganizationTypes/Create')
            ->has('allTypes')
        );
});

test('organization type edit page receives allTypes prop', function (): void {
    $type = makeHierarchyOrgType('EDIT_ALL');

    $this->actingAs(hierarchyAdminUser())
        ->get(route('organization-types.edit', $type))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('OrganizationTypes/Edit')
            ->has('allTypes')
        );
});
