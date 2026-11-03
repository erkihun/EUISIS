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
        ->and($groups->clone()->where('level', '!=', 'unit')->whereNull('name_am')->count())->toBe(0);

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
