<?php

declare(strict_types=1);

use App\Models\GradeLevel;
use App\Models\Occupation;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\OrganizationUnitType;
use App\Models\Position;
use App\Models\Role;
use App\Models\User;
use App\Services\Organizations\OrganizationTreeService;
use Spatie\Permission\Models\Permission;

/*
 * The organization flow's production rules: Amharic names, a unit type from
 * the catalog, a grade from the Grade Levels list, one head position per unit,
 * and only the four organization-to-organization relationship types offered.
 */

beforeEach(function (): void {
    $permissions = [
        'organizations.view', 'organizations.create', 'organizations.update',
        'organization-units.viewAny', 'organization-units.view', 'organization-units.create', 'organization-units.update',
        'positions.viewAny', 'positions.view', 'positions.create', 'positions.update', 'positions.move',
    ];
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    Role::findOrCreate('Super Admin', 'web')->givePermissionTo($permissions);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('Super Admin');

    $this->type = OrganizationType::query()->create(['code' => 'OPF-TYPE', 'name_en' => 'Bureau', 'name_am' => 'ቢሮ']);
    $this->organization = Organization::query()->create([
        'organization_type_id' => $this->type->id, 'code' => 'OPF-ORG',
        'name_en' => 'Production Bureau', 'name_am' => 'የምርት ቢሮ', 'status' => 'active',
    ]);
    $this->unitType = OrganizationUnitType::query()->create(['code' => 'directorate', 'name_en' => 'Directorate', 'name_am' => 'ዳይሬክቶሬት', 'is_active' => true]);
    $this->unit = OrganizationUnit::query()->create([
        'organization_id' => $this->organization->id, 'organization_unit_type_id' => $this->unitType->id, 'unit_type' => 'directorate',
        'code' => 'OPF-U1', 'name_en' => 'Planning', 'name_am' => 'ዕቅድ', 'status' => 'active',
    ]);
    $this->otherUnit = OrganizationUnit::query()->create([
        'organization_id' => $this->organization->id, 'organization_unit_type_id' => $this->unitType->id, 'unit_type' => 'directorate',
        'code' => 'OPF-U2', 'name_en' => 'Finance', 'name_am' => 'ፋይናንስ', 'status' => 'active',
    ]);
    $this->occupation = Occupation::query()->create(['code' => 'OPF-OCC', 'isco_code' => '2422', 'name_en' => 'Planner', 'is_active' => true]);
    GradeLevel::query()->create(['name' => 'Grade IX']);
});

function opfPosition(array $overrides = []): array
{
    static $n = 0;
    $n++;

    return array_merge([
        'job_position_code' => 'OPF-P'.$n,
        'organization_id' => test()->organization->id,
        'organization_unit_id' => test()->unit->id,
        'occupation_id' => test()->occupation->id,
        'title_en' => 'Senior Planner',
        'title_am' => 'ከፍተኛ ዕቅድ አውጪ',
        'is_active' => true,
    ], $overrides);
}

it('requires the Amharic name of an organization', function (): void {
    $this->actingAs($this->admin)
        ->post(route('organizations.store'), [
            'organization_type_id' => $this->type->id, 'name_en' => 'No Amharic', 'status' => 'active',
        ])
        ->assertSessionHasErrors('name_am');
});

it('requires the Amharic name and a catalog type for a unit', function (): void {
    $this->actingAs($this->admin)
        ->post(route('organization-units.store'), [
            'organization_id' => $this->organization->id, 'name_en' => 'No Amharic', 'status' => 'active',
        ])
        ->assertSessionHasErrors(['name_am', 'organization_unit_type_id']);
});

it('requires both titles of a position', function (): void {
    $this->actingAs($this->admin)
        ->post(route('positions.store'), opfPosition(['title_am' => '', 'title_en' => '']))
        ->assertSessionHasErrors(['title_am', 'title_en']);
});

it('takes the grade from the Grade Levels list', function (): void {
    $this->actingAs($this->admin)
        ->post(route('positions.store'), opfPosition(['grade_level' => 'IX']))
        ->assertSessionHasErrors('grade_level');

    $this->actingAs($this->admin)
        ->post(route('positions.store'), opfPosition(['grade_level' => 'Grade IX']))
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect(Position::query()->where('grade_level', 'Grade IX')->exists())->toBeTrue();
});

it('keeps a legacy grade a position already carries when it is edited', function (): void {
    $position = Position::query()->create([
        'organization_id' => $this->organization->id, 'organization_unit_id' => $this->unit->id, 'occupation_id' => $this->occupation->id,
        'job_position_code' => 'OPF-LEGACY', 'title_en' => 'Clerk', 'title_am' => 'ጸሐፊ', 'grade_level' => 'IX (old)', 'is_active' => true,
    ]);

    $this->actingAs($this->admin)
        ->patch(route('positions.update', $position), opfPosition([
            'job_position_code' => 'OPF-LEGACY', 'title_am' => 'ዋና ጸሐፊ', 'grade_level' => 'IX (old)',
        ]))
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect($position->fresh()->title_am)->toBe('ዋና ጸሐፊ');

    $this->actingAs($this->admin)
        ->patch(route('positions.update', $position), opfPosition([
            'job_position_code' => 'OPF-LEGACY', 'grade_level' => 'X (made up)',
        ]))
        ->assertSessionHasErrors('grade_level');
});

it('allows one head position per unit', function (): void {
    $this->actingAs($this->admin)
        ->post(route('positions.store'), opfPosition(['is_unit_head' => true]))
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $this->actingAs($this->admin)
        ->post(route('positions.store'), opfPosition(['title_am' => 'ሁለተኛ ኃላፊ', 'is_unit_head' => true]))
        ->assertSessionHasErrors('is_unit_head');

    // Another unit can have its own head.
    $this->actingAs($this->admin)
        ->post(route('positions.store'), opfPosition(['organization_unit_id' => $this->otherUnit->id, 'is_unit_head' => true]))
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect(Position::query()->where('is_unit_head', true)->count())->toBe(2);
});

it('refuses marking a second head when editing', function (): void {
    Position::query()->create([
        'organization_id' => $this->organization->id, 'organization_unit_id' => $this->unit->id, 'occupation_id' => $this->occupation->id,
        'job_position_code' => 'OPF-HEAD', 'title_en' => 'Director', 'title_am' => 'ዳይሬክተር', 'is_unit_head' => true, 'is_active' => true,
    ]);
    $deputy = Position::query()->create([
        'organization_id' => $this->organization->id, 'organization_unit_id' => $this->unit->id, 'occupation_id' => $this->occupation->id,
        'job_position_code' => 'OPF-DEP', 'title_en' => 'Deputy', 'title_am' => 'ምክትል', 'is_active' => true,
    ]);

    $this->actingAs($this->admin)
        ->patch(route('positions.update', $deputy), opfPosition(['job_position_code' => 'OPF-DEP', 'is_unit_head' => true]))
        ->assertSessionHasErrors('is_unit_head');
});

it('drops the head mark when the position moves to another unit', function (): void {
    $head = Position::query()->create([
        'organization_id' => $this->organization->id, 'organization_unit_id' => $this->unit->id, 'occupation_id' => $this->occupation->id,
        'job_position_code' => 'OPF-MOVE', 'title_en' => 'Director', 'title_am' => 'ዳይሬክተር', 'is_unit_head' => true, 'is_active' => true,
    ]);

    $this->actingAs($this->admin)
        ->post(route('positions.move.store', $head), [
            'target_organization_unit_id' => $this->otherUnit->id,
            'reason' => 'Restructured.',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect($head->fresh()->organization_unit_id)->toBe($this->otherUnit->id)
        ->and($head->fresh()->is_unit_head)->toBeFalse();
});

it('offers only the four organization-to-organization relationship types', function (): void {
    expect(app(OrganizationTreeService::class)->relationshipTypeOptions())
        ->toBe(['reports_to', 'geographically_under', 'service_scope', 'oversight']);
});
