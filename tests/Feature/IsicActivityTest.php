<?php

declare(strict_types=1);

use App\Models\IsicActivity;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    foreach ([
        'isic-activities.viewAny',
        'isic-activities.view',
        'isic-activities.create',
        'isic-activities.update',
        'isic-activities.delete',
        'isic-activities.restore',
    ] as $perm) {
        Permission::findOrCreate($perm, 'web');
    }

    Role::findOrCreate('Isic Admin', 'web')->syncPermissions([
        'isic-activities.viewAny', 'isic-activities.view', 'isic-activities.create',
        'isic-activities.update', 'isic-activities.delete', 'isic-activities.restore',
    ]);
    Role::findOrCreate('Isic Viewer', 'web')->syncPermissions(['isic-activities.viewAny']);
});

function isicAdmin(): User
{
    $u = User::factory()->create();
    $u->assignRole('Isic Admin');

    return $u;
}

function isicViewer(): User
{
    $u = User::factory()->create();
    $u->assignRole('Isic Viewer');

    return $u;
}

test('isic_code is required when creating an ISIC activity', function (): void {
    $this->actingAs(isicAdmin())
        ->post(route('isic-activities.store'), [
            'level' => 'section',
            'name_en' => 'Test',
            'is_active' => true,
        ])
        ->assertSessionHasErrors('isic_code');
});

test('isic_code must be unique', function (): void {
    IsicActivity::query()->create([
        'isic_code' => 'O',
        'level' => 'section',
        'section_code' => 'O',
        'name_en' => 'Existing',
        'is_active' => true,
    ]);

    $this->actingAs(isicAdmin())
        ->post(route('isic-activities.store'), [
            'isic_code' => 'O',
            'level' => 'section',
            'section_code' => 'O',
            'name_en' => 'Duplicate',
            'is_active' => true,
        ])
        ->assertSessionHasErrors('isic_code');
});

test('level must be one of section, division, group, class', function (): void {
    $this->actingAs(isicAdmin())
        ->post(route('isic-activities.store'), [
            'isic_code' => 'X1',
            'level' => 'invalid',
            'name_en' => 'Test',
            'is_active' => true,
        ])
        ->assertSessionHasErrors('level');
});

test('admin can create and update an ISIC activity', function (): void {
    $this->actingAs(isicAdmin())
        ->post(route('isic-activities.store'), [
            'isic_code' => '8411',
            'level' => 'class',
            'section_code' => 'O',
            'division_code' => '84',
            'group_code' => '841',
            'class_code' => '8411',
            'name_en' => 'General Public Administration Activities',
            'is_active' => true,
        ])
        ->assertRedirect();

    $activity = IsicActivity::query()->where('isic_code', '8411')->firstOrFail();
    expect($activity->level)->toBe('class');

    $this->patch(route('isic-activities.update', $activity), [
        'isic_code' => '8411',
        'level' => 'class',
        'section_code' => 'O',
        'division_code' => '84',
        'group_code' => '841',
        'class_code' => '8411',
        'name_en' => 'Updated Name',
        'is_active' => true,
    ])->assertRedirect();

    expect($activity->fresh()->name_en)->toBe('Updated Name');
});

test('unauthorized user cannot create an ISIC activity', function (): void {
    $this->actingAs(isicViewer())
        ->post(route('isic-activities.store'), [
            'isic_code' => 'X',
            'level' => 'section',
            'name_en' => 'Test',
            'is_active' => true,
        ])
        ->assertForbidden();
});

test('the list filters by section and counts each level without the level filter', function (): void {
    foreach ([
        ['A', 'section', 'A'], ['01', 'division', 'A'], ['011', 'group', 'A'], ['J', 'section', 'J'],
    ] as [$code, $level, $section]) {
        IsicActivity::query()->create(['isic_code' => $code, 'level' => $level, 'section_code' => $section, 'name_en' => "Name {$code}", 'is_active' => true]);
    }

    $this->actingAs(isicViewer())
        ->get(route('isic-activities.index', ['section' => 'A', 'level' => 'group']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('isicActivities', 1)
            ->where('isicActivities.0.isic_code', '011')
            ->where('levelCounts.section', 1)
            ->where('levelCounts.division', 1)
            ->where('levelCounts.group', 1)
            ->has('sections', 2)
            ->where('sections.0.code', 'A'));
});
