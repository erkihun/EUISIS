<?php

declare(strict_types=1);

use App\Enums\OrganizationDesignStatus;
use App\Enums\OrganizationScopeType;
use App\Enums\OrganizationStatus;
use App\Models\InstitutionMandate;
use App\Models\LegacyBaseline;
use App\Models\LegalInstrumentType;
use App\Models\LegalSource;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\OrganizationUnitType;
use App\Models\Position;
use App\Models\User;
use App\Models\UserOrganizationScope;
use App\Services\OrganizationDesign\LegacyBaselineService;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
 * The module landing page, its figures, "as of date X", the organogram of a
 * published baseline and the comparison of two baselines
 * (docs/organization-design-legacy-baseline-plan.md, phase D).
 */

beforeEach(function (): void {
    foreach (array_column(require base_path('database/seeders/data/organization-design-permissions.php'), 'name') as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    Role::findOrCreate('Super Admin', 'web');
    Role::findOrCreate('OV Designer', 'web')->syncPermissions(['legacy-baselines.viewAny', 'legacy-baselines.view', 'legacy-baselines.create', 'legacy-baselines.update', 'legal-sources.viewAny', 'institution-mandates.viewAny']);
    Role::findOrCreate('OV Approver', 'web')->syncPermissions(['legacy-baselines.viewAny', 'legacy-baselines.view', 'legacy-baselines.review', 'legacy-baselines.approve', 'legacy-baselines.publish']);
    LegalInstrumentType::query()->firstOrCreate(['code' => 'PROCLAMATION'], ['name_en' => 'Proclamation', 'sort_order' => 10]);
});

function ovOrganization(string $suffix): Organization
{
    $type = OrganizationType::query()->firstOrCreate(['code' => 'OV-BUREAU'], ['name_en' => 'OV Bureau', 'is_demo' => false]);

    return Organization::query()->create(['organization_type_id' => $type->id, 'code' => 'OV-'.$suffix.'-'.uniqid(), 'name_en' => 'OV Org '.$suffix, 'status' => OrganizationStatus::Active, 'effective_from' => now()->subYears(20)->toDateString(), 'is_demo' => false]);
}

function ovUser(Organization $organization, string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);
    UserOrganizationScope::query()->create(['user_id' => $user->id, 'organization_id' => $organization->id, 'scope_type' => OrganizationScopeType::Self, 'is_active' => true, 'effective_from' => now()->subDay()->toDateString()]);

    return $user->fresh();
}

test('the landing page shows only the areas the user may open and figures from their own scope', function (): void {
    $mine = ovOrganization('A');
    $theirs = ovOrganization('B');
    $designer = ovUser($mine, 'OV Designer');
    foreach ([$mine, $theirs] as $organization) {
        $unitType = OrganizationUnitType::query()->firstOrCreate(['code' => 'OV-DIR'], ['name_en' => 'Directorate', 'is_active' => true]);
        $unit = OrganizationUnit::query()->create(['organization_id' => $organization->id, 'organization_unit_type_id' => $unitType->id, 'code' => 'U-'.substr(uniqid(), -5), 'name_en' => 'Unit', 'unit_type' => 'directorate', 'status' => 'active']);
        Position::query()->create(['organization_id' => $organization->id, 'organization_unit_id' => $unit->id, 'job_position_code' => 'P-'.substr(uniqid(), -5), 'title_en' => 'Officer', 'is_active' => true]);
    }

    $this->actingAs($designer)->get(route('organization-design.overview'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('OrganizationDesign/Overview')
        ->where('kpis.positions', 1)->where('kpis.positions_vacant', 1)->where('kpis.organizations_without_mandate', 1)
        ->where('categories', fn ($categories) => collect($categories)->pluck('key')->sort()->values()->all() === ['legacy', 'legal']));

    $this->actingAs($designer)->get(route('organization-design.overview', ['organization_id' => $theirs->id]))->assertForbidden();
    $this->actingAs(User::factory()->create())->get(route('organization-design.overview'))->assertForbidden();
});

test('as of a date shows the mandate version then in force and the published baseline describing it; two baselines compare by code', function (): void {
    $organization = ovOrganization('C');
    $designer = ovUser($organization, 'OV Designer');
    $approver = ovUser($organization, 'OV Approver');
    $service = app(LegacyBaselineService::class);
    $source = LegalSource::query()->create(['organization_id' => $organization->id, 'code' => 'P-1', 'title_en' => 'Proclamation', 'source_type_id' => LegalInstrumentType::query()->value('id'), 'status' => 'active']);
    $lineage = (string) Str::uuid();
    InstitutionMandate::query()->create(['organization_id' => $organization->id, 'lineage_key' => $lineage, 'version_no' => 1, 'code' => 'MND', 'title_en' => 'Old mandate', 'legal_instrument_type_id' => $source->source_type_id, 'legal_instrument_number' => '1/2010', 'effective_from' => '2010-01-01', 'effective_to' => '2019-12-31', 'status' => OrganizationDesignStatus::Superseded]);
    InstitutionMandate::query()->create(['organization_id' => $organization->id, 'lineage_key' => $lineage, 'version_no' => 2, 'code' => 'MND', 'title_en' => 'New mandate', 'legal_instrument_type_id' => $source->source_type_id, 'legal_instrument_number' => '2/2020', 'effective_from' => '2020-01-01', 'status' => OrganizationDesignStatus::Published]);
    $unitType = OrganizationUnitType::query()->firstOrCreate(['code' => 'OV-DIR'], ['name_en' => 'Directorate', 'is_active' => true]);
    $hr = OrganizationUnit::query()->create(['organization_id' => $organization->id, 'organization_unit_type_id' => $unitType->id, 'code' => 'HR', 'name_en' => 'HR Directorate', 'unit_type' => 'directorate', 'status' => 'active']);

    $publish = function (string $title, string $date) use ($service, $designer, $approver, $organization, $source): LegacyBaseline {
        $b = $service->create($designer, $organization->id, ['title_en' => $title, 'origin' => 'manual', 'legal_source_id' => $source->id, 'effective_date' => $date]);
        $service->submit($designer, $b);
        $service->approve($approver, $b->fresh(), null);

        return $service->publish($approver, $b->fresh());
    };
    $first = $publish('Baseline 2015', '2015-07-01');
    // Between the two, a unit is renamed and another added.
    $hr->forceFill(['name_en' => 'Human Resource Directorate'])->save();
    OrganizationUnit::query()->create(['organization_id' => $organization->id, 'organization_unit_type_id' => $unitType->id, 'parent_unit_id' => $hr->id, 'code' => 'REC', 'name_en' => 'Records Team', 'unit_type' => 'team', 'status' => 'active']);
    $second = $publish('Baseline 2021', '2021-07-01');

    $this->actingAs($designer)->get(route('organization-design.overview', ['organization_id' => $organization->id, 'as_of' => '2016-05-01']))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('asOf.mandates.0.title_en', 'Old mandate')->where('asOf.baseline.id', $first->id));
    $this->actingAs($designer)->get(route('organization-design.overview', ['organization_id' => $organization->id, 'as_of' => '2022-01-01']))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('asOf.mandates.0.title_en', 'New mandate')->where('asOf.baseline.id', $second->id));

    $diff = $this->actingAs($designer)->getJson(route('organization-design.legacy-baselines.compare', [$second, $first]))->assertOk()->json();
    expect($diff['older']['id'])->toBe($first->id)
        ->and(collect($diff['units']['added'])->pluck('code')->all())->toBe(['REC'])
        ->and($diff['units']['changed'][0]['code'])->toBe('HR')->and($diff['units']['changed'][0]['changes']['name_en']['to'])->toBe('Human Resource Directorate')
        ->and($diff['units']['removed'])->toBe([]);

    $this->actingAs(ovUser(ovOrganization('D'), 'OV Designer'))->getJson(route('organization-design.legacy-baselines.compare', [$second, $first]))->assertForbidden();
});
