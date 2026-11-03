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
