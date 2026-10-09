<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\OrganizationType;
use Database\Seeders\DatabaseSeeder;

test('the database seeder can run again on a database it already seeded', function (): void {
    $this->seed(DatabaseSeeder::class);
    $typeIds = OrganizationType::query()->pluck('id', 'code');

    $this->seed(DatabaseSeeder::class);

    expect(OrganizationType::query()->pluck('id', 'code')->all())->toEqual($typeIds->all())
        ->and(OrganizationType::query()->where('code', 'CITY_GOVERNMENT')->count())->toBe(1)
        ->and(Organization::query()->where('code', 'AA-ROOT')->count())->toBe(1);
});

test('the database seeder reuses an organization type that already exists outside the demo data', function (): void {
    $existing = OrganizationType::query()->create(['code' => 'CITY_GOVERNMENT', 'prefix' => 'AA', 'name_en' => 'City Administration']);

    $this->seed(DatabaseSeeder::class);

    expect(OrganizationType::query()->where('code', 'CITY_GOVERNMENT')->sole()->only(['id', 'prefix', 'name_en']))
        ->toBe(['id' => $existing->id, 'prefix' => 'AA', 'name_en' => 'City Administration'])
        ->and(Organization::query()->where('code', 'AA-ROOT')->value('organization_type_id'))->toBe($existing->id);
});

test('the database seeder refuses to build demo data on an archived organization type', function (): void {
    OrganizationType::query()->create(['code' => 'CITY_GOVERNMENT', 'name_en' => 'City Government'])->delete();

    $this->seed(DatabaseSeeder::class);
})->throws(RuntimeException::class, 'Organization type CITY_GOVERNMENT is archived');
