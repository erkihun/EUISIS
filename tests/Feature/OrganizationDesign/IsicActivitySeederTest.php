<?php

declare(strict_types=1);

use App\Models\IsicActivity;
use Database\Seeders\IsicActivitySeeder;

test('the ISIC Rev. 4 seeder loads the full four-level structure with Amharic names', function (): void {
    $this->seed(IsicActivitySeeder::class);

    $activities = IsicActivity::query()->where('revision', IsicActivitySeeder::REVISION);

    expect($activities->clone()->where('level', 'section')->count())->toBe(21)
        ->and($activities->clone()->where('level', 'division')->count())->toBe(88)
        ->and($activities->clone()->where('level', 'group')->count())->toBe(238)
        ->and($activities->clone()->where('level', 'class')->count())->toBe(419)
        ->and($activities->clone()->where('level', '!=', 'section')->whereNull('parent_id')->count())->toBe(0)
        ->and($activities->clone()->whereNull('name_am')->count())->toBe(0);

    $software = IsicActivity::query()->where('isic_code', '6201')->firstOrFail();
    expect($software->name_en)->toBe('Computer programming activities')
        ->and($software->section_code)->toBe('J')
        ->and($software->division_code)->toBe('62')
        ->and($software->group_code)->toBe('620')
        ->and($software->class_code)->toBe('6201')
        ->and($software->parent?->isic_code)->toBe('620')
        ->and($software->parent?->parent?->isic_code)->toBe('62')
        ->and($software->parent?->parent?->parent?->isic_code)->toBe('J');

    expect(IsicActivity::query()->where('isic_code', '0111')->value('division_code'))->toBe('01')
        ->and(IsicActivity::query()->orderBy('sort_order')->value('isic_code'))->toBe('A');
});

test('re-running the ISIC seeder keeps recorded names and archived rows', function (): void {
    $curated = IsicActivity::query()->create([
        'isic_code' => '8411', 'level' => 'class', 'name_en' => 'Curated English', 'name_am' => 'የተመዘገበ ስም', 'is_active' => true,
    ]);
    $blankAmharic = IsicActivity::query()->create([
        'isic_code' => '841', 'level' => 'group', 'name_en' => 'Curated group', 'is_active' => true,
    ]);
    $archived = IsicActivity::query()->create([
        'isic_code' => '8421', 'level' => 'class', 'name_en' => 'Archived row', 'is_active' => false,
    ]);
    $archived->delete();

    $this->seed(IsicActivitySeeder::class);
    $this->seed(IsicActivitySeeder::class);

    expect(IsicActivity::withTrashed()->count())->toBe(766);

    $curated->refresh();
    expect($curated->name_en)->toBe('Curated English')
        ->and($curated->name_am)->toBe('የተመዘገበ ስም')
        ->and($curated->parent?->isic_code)->toBe('841');

    $blankAmharic->refresh();
    expect($blankAmharic->name_en)->toBe('Curated group')
        ->and($blankAmharic->name_am)->not->toBeNull();

    $archived = IsicActivity::withTrashed()->where('isic_code', '8421')->firstOrFail();
    expect($archived->trashed())->toBeTrue()
        ->and($archived->is_active)->toBeFalse();
});
