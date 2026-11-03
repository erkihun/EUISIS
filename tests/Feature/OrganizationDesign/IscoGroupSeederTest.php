<?php

declare(strict_types=1);

use App\Models\IscoGroup;
use App\Models\Occupation;
use Database\Seeders\IscoGroupSeeder;
use Database\Seeders\OccupationSeeder;

test('the ISCO-08 seeder loads the full four-level structure from the dataset file', function (): void {
    $this->seed(IscoGroupSeeder::class);

    $groups = IscoGroup::query()->where('revision', IscoGroupSeeder::REVISION);

    expect($groups->clone()->where('level', 'major')->count())->toBe(10)
        ->and($groups->clone()->where('level', 'sub_major')->count())->toBe(43)
        ->and($groups->clone()->where('level', 'minor')->count())->toBe(130)
        ->and($groups->clone()->where('level', 'unit')->count())->toBe(436)
        ->and($groups->clone()->where('level', '!=', 'major')->whereNull('parent_id')->count())->toBe(0)
        ->and($groups->clone()->whereNull('name_am')->count())->toBe(0);

    $accountants = IscoGroup::query()->where('revision', IscoGroupSeeder::REVISION)->where('code', '2411')->firstOrFail();
    expect($accountants->name_en)->toBe('Accountants')
        ->and($accountants->parent?->code)->toBe('241')
        ->and($accountants->parent?->parent?->code)->toBe('24')
        ->and($accountants->parent?->parent?->parent?->code)->toBe('2')
        ->and($accountants->parent?->parent?->parent?->name_am)->toBe('ባለሙያዎች');
});

test('re-running the ISCO-08 seeder is idempotent and links occupations to their unit group', function (): void {
    $this->seed(OccupationSeeder::class);
    $this->seed(IscoGroupSeeder::class);
    $count = IscoGroup::query()->count();

    $this->seed(IscoGroupSeeder::class);

    expect(IscoGroup::query()->count())->toBe($count);

    $developers = Occupation::query()->where('isco_code', '2512')->firstOrFail();
    expect($developers->iscoGroup?->code)->toBe('2512')
        ->and($developers->iscoGroup?->level)->toBe('unit')
        ->and(Occupation::query()->whereNull('isco_group_id')->count())->toBe(0);
});

test('every ISCO-08 unit group gets one occupation and existing occupations keep their names', function (): void {
    $this->seed(OccupationSeeder::class);
    Occupation::query()->where('isco_code', '2411')->update(['name_en' => 'Curated accountants']);
    Occupation::query()->where('isco_code', '2423')->update(['name_am' => 'Curated Amharic']);
    Occupation::query()->where('isco_code', '2611')->firstOrFail()->delete();

    $this->seed(IscoGroupSeeder::class);
    $this->seed(IscoGroupSeeder::class);

    expect(Occupation::withTrashed()->count())->toBe(436)
        ->and(Occupation::query()->count())->toBe(435)
        ->and(Occupation::query()->whereNull('isco_group_id')->count())->toBe(0)
        ->and(Occupation::query()->whereNull('name_am')->count())->toBe(0)
        ->and(Occupation::query()->where('isco_code', '2423')->value('name_am'))->toBe('Curated Amharic')
        ->and(Occupation::query()->where('isco_code', '2411')->value('name_en'))->toBe('Curated accountants');

    $accountants = Occupation::query()->where('isco_code', '2411')->firstOrFail();
    expect($accountants->name_am)->toBe($accountants->iscoGroup?->name_am);

    $seeded = Occupation::query()->where('isco_code', '0110')->firstOrFail();
    expect($seeded->code)->toBe('0110')
        ->and($seeded->isco_major_group_code)->toBe('0')
        ->and($seeded->isco_sub_major_group_code)->toBe('01')
        ->and($seeded->isco_minor_group_code)->toBe('011')
        ->and($seeded->isco_unit_group_code)->toBe('0110')
        ->and($seeded->iscoGroup?->code)->toBe('0110')
        ->and($seeded->is_active)->toBeTruthy();
});

test('every occupation takes its minor group as skill specialization in both languages unless one is recorded', function (): void {
    $this->seed(OccupationSeeder::class);
    Occupation::query()->where('isco_code', '2423')->update(['skill_specialization' => 'Curated specialization']);

    $this->seed(IscoGroupSeeder::class);

    expect(Occupation::query()->whereNull('skill_specialization')->count())->toBe(0)
        ->and(Occupation::query()->whereNull('skill_specialization_am')->count())->toBe(0)
        ->and(Occupation::query()->where('isco_code', '2423')->value('skill_specialization'))->toBe('Curated specialization')
        ->and(Occupation::query()->where('isco_code', '2423')->value('skill_specialization_am'))->not->toBeNull();

    $accountants = Occupation::query()->where('isco_code', '2411')->firstOrFail();
    expect($accountants->skill_specialization)->toBe('Finance professionals')
        ->and($accountants->skill_specialization_am)->toBe(IscoGroup::query()->where('code', '241')->value('name_am'));
});

test('every occupation gets the ILO skill level and a description in both languages unless recorded', function (): void {
    $this->seed(OccupationSeeder::class);
    Occupation::query()->where('isco_code', '2423')->update(['skill_level' => '3', 'description_en' => 'Curated description']);

    $this->seed(IscoGroupSeeder::class);

    expect(Occupation::query()->whereNull('skill_level')->count())->toBe(0)
        ->and(Occupation::query()->whereNull('description_en')->count())->toBe(0)
        ->and(Occupation::query()->whereNull('description_am')->count())->toBe(0);

    $levels = Occupation::query()->whereIn('isco_code', ['0110', '0210', '0310', '1211', '1411', '2411', '3353', '4110', '8322', '9111'])
        ->pluck('skill_level', 'isco_code')->all();

    expect($levels)->toMatchArray([
        '0110' => '4', '0210' => '2', '0310' => '1', '1211' => '4', '1411' => '3',
        '2411' => '4', '3353' => '3', '4110' => '2', '8322' => '2', '9111' => '1',
    ]);

    $curated = Occupation::query()->where('isco_code', '2423')->firstOrFail();
    expect($curated->skill_level)->toBe('3')
        ->and($curated->description_en)->toBe('Curated description')
        ->and($curated->description_am)->not->toBeNull();
});
