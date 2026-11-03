<?php

declare(strict_types=1);

use App\Models\Occupation;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    foreach ([
        'occupations.viewAny',
        'occupations.view',
        'occupations.create',
        'occupations.update',
        'occupations.delete',
        'occupations.restore',
        'occupations.export',
    ] as $perm) {
        Permission::findOrCreate($perm, 'web');
    }

    Role::findOrCreate('Occ Admin', 'web')->syncPermissions([
        'occupations.viewAny', 'occupations.view', 'occupations.create',
        'occupations.update', 'occupations.delete', 'occupations.restore',
    ]);
    Role::findOrCreate('Occ Viewer', 'web')->syncPermissions(['occupations.viewAny']);
});

function occAdmin(): User
{
    $u = User::factory()->create();
    $u->assignRole('Occ Admin');

    return $u;
}

function occViewer(): User
{
    $u = User::factory()->create();
    $u->assignRole('Occ Viewer');

    return $u;
}

test('isco_code is required when creating an occupation', function (): void {
    $this->actingAs(occAdmin())
        ->post(route('occupations.store'), [
            'name_en' => 'Test',
            'is_active' => true,
        ])
        ->assertSessionHasErrors('isco_code');
});

test('isco_code must be unique', function (): void {
    Occupation::query()->create([
        'code' => '2512',
        'isco_code' => '2512',
        'name_en' => 'Existing',
        'is_active' => true,
    ]);

    $this->actingAs(occAdmin())
        ->post(route('occupations.store'), [
            'isco_code' => '2512',
            'name_en' => 'Duplicate',
            'is_active' => true,
        ])
        ->assertSessionHasErrors('isco_code');
});

test('admin can create occupation with ISCO code', function (): void {
    $this->actingAs(occAdmin())
        ->post(route('occupations.store'), [
            'isco_code' => '2611',
            'isco_major_group_code' => '2',
            'isco_unit_group_code' => '2611',
            'name_en' => 'Lawyers',
            'skill_level' => '4',
            'is_active' => true,
        ])
        ->assertRedirect();

    expect(Occupation::query()->where('isco_code', '2611')->exists())->toBeTrue();
});

test('invalid isco_code is rejected', function (): void {
    $this->actingAs(occAdmin())
        ->post(route('occupations.store'), [
            'isco_code' => 'BAD-CODE!',
            'name_en' => 'Test',
            'is_active' => true,
        ])
        ->assertSessionHasErrors('isco_code');
});

test('admin can update occupation', function (): void {
    $occ = Occupation::query()->create([
        'code' => '4110',
        'isco_code' => '4110',
        'name_en' => 'Clerks',
        'is_active' => true,
    ]);

    $this->actingAs(occAdmin())
        ->patch(route('occupations.update', $occ), [
            'isco_code' => '4110',
            'name_en' => 'General Office Clerks',
            'is_active' => true,
        ])
        ->assertRedirect();

    expect($occ->fresh()->name_en)->toBe('General Office Clerks');
});

test('unauthorized user cannot create occupation', function (): void {
    $this->actingAs(occViewer())
        ->post(route('occupations.store'), [
            'isco_code' => '2611',
            'name_en' => 'Test',
            'is_active' => true,
        ])
        ->assertForbidden();
});

test('skill specialization is saved in both languages on create and update', function (): void {
    $this->actingAs(occAdmin())
        ->post(route('occupations.store'), [
            'isco_code' => '2411',
            'name_en' => 'Accountants',
            'skill_specialization' => 'Finance professionals',
            'skill_specialization_am' => 'Amharic value',
            'is_active' => true,
        ])
        ->assertSessionHasNoErrors();

    $occupation = Occupation::query()->where('isco_code', '2411')->firstOrFail();
    expect($occupation->skill_specialization)->toBe('Finance professionals')
        ->and($occupation->skill_specialization_am)->toBe('Amharic value');

    $this->actingAs(occAdmin())
        ->patch(route('occupations.update', $occupation), [
            'isco_code' => '2411',
            'name_en' => 'Accountants',
            'skill_specialization' => 'Finance',
            'skill_specialization_am' => 'Updated Amharic',
        ])
        ->assertSessionHasNoErrors();

    $occupation->refresh();
    expect($occupation->skill_specialization)->toBe('Finance')
        ->and($occupation->skill_specialization_am)->toBe('Updated Amharic');
});

test('creating an occupation sets its hierarchy and takes the skill level from the ISCO code when blank', function (): void {
    $this->actingAs(occAdmin())
        ->post(route('occupations.store'), [
            'isco_code' => '1411',
            'name_en' => 'Hotel managers',
            'description_en' => 'English text',
            'description_am' => 'Amharic text',
        ])
        ->assertSessionHasNoErrors();

    $occupation = Occupation::query()->where('isco_code', '1411')->firstOrFail();
    expect($occupation->skill_level)->toBe('3')
        ->and($occupation->isco_major_group_code)->toBe('1')
        ->and($occupation->isco_sub_major_group_code)->toBe('14')
        ->and($occupation->isco_minor_group_code)->toBe('141')
        ->and($occupation->isco_unit_group_code)->toBe('1411')
        ->and($occupation->description_en)->toBe('English text')
        ->and($occupation->description_am)->toBe('Amharic text');
});

test('a chosen skill level is kept and an unknown one is refused', function (): void {
    $this->actingAs(occAdmin())
        ->post(route('occupations.store'), ['isco_code' => '2411', 'name_en' => 'Accountants', 'skill_level' => '5'])
        ->assertSessionHasErrors('skill_level');

    $this->actingAs(occAdmin())
        ->post(route('occupations.store'), ['isco_code' => '2411', 'name_en' => 'Accountants', 'skill_level' => '3'])
        ->assertSessionHasNoErrors();

    expect(Occupation::query()->where('isco_code', '2411')->value('skill_level'))->toBe('3');
});

test('the list filters by skill level', function (): void {
    Occupation::query()->create(['code' => '2411', 'isco_code' => '2411', 'name_en' => 'Accountants', 'skill_level' => '4', 'is_active' => true]);
    Occupation::query()->create(['code' => '9111', 'isco_code' => '9111', 'name_en' => 'Domestic cleaners', 'skill_level' => '1', 'is_active' => true]);

    $this->actingAs(occViewer())
        ->get(route('occupations.index', ['skill_level' => '1']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('occupations', 1)->where('occupations.0.isco_code', '9111'));
});
