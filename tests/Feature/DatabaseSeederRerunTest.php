<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\OrganizationType;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

test('the database seeder can run again on a database it already seeded', function (): void {
    $this->seed(DatabaseSeeder::class);
    $typeIds = OrganizationType::query()->pluck('id', 'code');

    $this->seed(DatabaseSeeder::class);

    expect(OrganizationType::query()->pluck('id', 'code')->all())->toEqual($typeIds->all())
        ->and(OrganizationType::query()->where('code', 'CITY_GOVERNMENT')->count())->toBe(1)
        ->and(Organization::query()->where('code', 'AA-ROOT')->count())->toBe(1);
});

test('a re-run keeps records that still reference a demo organization', function (): void {
    $this->seed(DatabaseSeeder::class);
    $bureau = Organization::query()->where('code', 'BUR-PSHRDB')->sole();
    $cycleId = (string) Str::uuid();
    DB::table('performance_cycles')->insert(['id' => $cycleId, 'code' => 'FY2026', 'name_en' => 'FY 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
    // performance_plans.organization_id restricts deletes, as on a live database.
    $planId = (string) Str::uuid();
    DB::table('performance_plans')->insert(['id' => $planId, 'cycle_id' => $cycleId, 'plan_type' => 'ORGANIZATION', 'organization_id' => $bureau->id, 'lineage_key' => (string) Str::uuid(), 'title' => 'Bureau plan']);

    $this->seed(DatabaseSeeder::class);

    expect(Organization::query()->where('code', 'BUR-PSHRDB')->sole()->id)->toBe($bureau->id)
        ->and(DB::table('performance_plans')->where('id', $planId)->value('organization_id'))->toBe($bureau->id);
});

test('the database seeder will not take over a real organization that uses a demo code', function (): void {
    $type = OrganizationType::query()->create(['code' => 'BUREAU_REAL', 'name_en' => 'Bureau']);
    Organization::query()->create(['organization_type_id' => $type->id, 'code' => 'BUR-PSHRDB', 'name_en' => 'Real bureau', 'status' => 'active', 'is_demo' => false]);

    $this->seed(DatabaseSeeder::class);
})->throws(RuntimeException::class, 'Organization BUR-PSHRDB already exists and is not demo data');

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
