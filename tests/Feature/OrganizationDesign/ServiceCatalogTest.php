<?php

declare(strict_types=1);

use App\Enums\OrganizationDesignStatus;
use App\Enums\OrganizationScopeType;
use App\Enums\OrganizationStatus;
use App\Models\ClassificationImportBatch;
use App\Models\InstitutionMandate;
use App\Models\IsicActivity;
use App\Models\LegalInstrumentType;
use App\Models\Organization;
use App\Models\OrganizationFunction;
use App\Models\OrganizationType;
use App\Models\Service;
use App\Models\ServiceClassification;
use App\Models\User;
use App\Models\UserOrganizationScope;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
 * Organization Design & Workforce Planning, phase 2: the service catalog and
 * its classifications. Brief tests 6–11 and 13–15 (12, ISCO, is phase 4).
 */

beforeEach(function (): void {
    foreach ([
        'service-catalog.viewAny', 'service-catalog.view', 'service-catalog.create', 'service-catalog.update', 'service-catalog.approve',
        'classifications.viewAny', 'classifications.manage', 'organization-functions.manage', 'institution-mandates.view',
    ] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    Role::findOrCreate('Super Admin', 'web');
    Role::findOrCreate('Service Designer', 'web')->syncPermissions([
        'service-catalog.viewAny', 'service-catalog.view', 'service-catalog.create', 'service-catalog.update',
        'classifications.viewAny', 'organization-functions.manage', 'institution-mandates.view',
    ]);
    Role::findOrCreate('Service Approver', 'web')->syncPermissions(['service-catalog.viewAny', 'service-catalog.view', 'service-catalog.approve']);
    Role::findOrCreate('Classification Steward', 'web')->syncPermissions(['classifications.viewAny', 'classifications.manage']);
});

function scOrganization(string $suffix): Organization
{
    $type = OrganizationType::query()->firstOrCreate(['code' => 'SC-BUREAU'], ['name_en' => 'SC Bureau', 'is_demo' => false]);

    return Organization::query()->create([
        'organization_type_id' => $type->id, 'code' => 'SC-'.$suffix.'-'.uniqid(), 'name_en' => 'SC Org '.$suffix,
        'status' => OrganizationStatus::Active, 'effective_from' => now()->subYear()->toDateString(), 'is_demo' => false,
    ]);
}

function scDesignerFor(Organization $organization): User
{
    $user = User::factory()->create();
    $user->assignRole('Service Designer');
    UserOrganizationScope::query()->create([
        'user_id' => $user->id, 'organization_id' => $organization->id, 'scope_type' => OrganizationScopeType::Self,
        'is_active' => true, 'effective_from' => now()->subDay()->toDateString(),
    ]);

    return $user->fresh();
}

function scPublishedMandate(Organization $organization): InstitutionMandate
{
    $type = LegalInstrumentType::query()->firstOrCreate(['code' => 'PROCLAMATION'], ['name_en' => 'Proclamation', 'sort_order' => 10]);

    return InstitutionMandate::query()->create([
        'organization_id' => $organization->id, 'lineage_key' => (string) Str::uuid(), 'version_no' => 1, 'code' => 'MND-'.substr(uniqid(), -4),
        'title_en' => 'Mandate', 'legal_instrument_type_id' => $type->id, 'legal_instrument_number' => '1/2020', 'effective_from' => '2020-01-01',
        'status' => OrganizationDesignStatus::Published,
    ]);
}

function scFunction(Organization $organization, string $code): OrganizationFunction
{
    $mandate = InstitutionMandate::query()->where('organization_id', $organization->id)->first() ?? scPublishedMandate($organization);
    $function = OrganizationFunction::query()->create([
        'organization_id' => $organization->id, 'mandate_id' => $mandate->id, 'code' => $code, 'title_en' => $code, 'path' => '', 'depth' => 0,
    ]);
    $function->forceFill(['path' => $function->id])->save();

    return $function;
}

function scLocalClassification(string $code = 'LGS-01', string $revision = '2026'): ServiceClassification
{
    return ServiceClassification::query()->create(['scheme' => 'LOCAL', 'revision' => $revision, 'code' => $code, 'level' => 1, 'name_en' => 'Civil registration']);
}

/** @return array<string, mixed> */
function scServicePayload(Organization $organization, array $overrides = []): array
{
    return [
        'organization_id' => $organization->id, 'code' => 'SRV-'.substr(uniqid(), -5), 'name_en' => 'Employee registration', 'name_am' => 'የሠራተኛ ምዝገባ',
        'beneficiary_type' => 'government', 'delivery_channel' => 'in_person', 'effective_from' => '2021-01-01', ...$overrides,
    ];
}

// 6 / 7 ─────────────────────────────────────────────────────────────────────
test('a service is justified by one or more functions, and a function produces many services', function (): void {
    $organization = scOrganization('A');
    $designer = scDesignerFor($organization);
    $f1 = scFunction($organization, 'F-1');
    $f2 = scFunction($organization, 'F-2');

    $this->actingAs($designer)->post(route('organization-design.services.store'), scServicePayload($organization, ['code' => 'SRV-1', 'function_ids' => [$f1->id, $f2->id]]))->assertRedirect();
    $this->actingAs($designer)->post(route('organization-design.services.store'), scServicePayload($organization, ['code' => 'SRV-2', 'function_ids' => [$f1->id]]))->assertRedirect();

    $s1 = Service::query()->where('code', 'SRV-1')->firstOrFail();
    expect($s1->functions()->pluck('code')->sort()->values()->all())->toBe(['F-1', 'F-2'])
        ->and($f1->services()->count())->toBe(2)
        ->and(Service::query()->count())->toBe(2);
});

// 8 ─────────────────────────────────────────────────────────────────────────
test('a service classification must be an active LOCAL entry, and a CPC reference must be a CPC entry', function (): void {
    $organization = scOrganization('B');
    $designer = scDesignerFor($organization);
    $local = scLocalClassification();
    $cpc = ServiceClassification::query()->create(['scheme' => 'CPC', 'revision' => '2.1', 'code' => '91', 'level' => 1, 'name_en' => 'Public administration']);
    $inactive = ServiceClassification::query()->create(['scheme' => 'LOCAL', 'revision' => '2026', 'code' => 'LGS-99', 'level' => 1, 'name_en' => 'Retired', 'is_active' => false]);

    $this->actingAs($designer)->from('/')->post(route('organization-design.services.store'), scServicePayload($organization, ['service_classification_id' => $cpc->id]))
        ->assertSessionHasErrors('service_classification_id');
    $this->actingAs($designer)->from('/')->post(route('organization-design.services.store'), scServicePayload($organization, ['service_classification_id' => $inactive->id]))
        ->assertSessionHasErrors('service_classification_id');
    $this->actingAs($designer)->from('/')->post(route('organization-design.services.store'), scServicePayload($organization, ['cpc_classification_id' => $local->id]))
        ->assertSessionHasErrors('cpc_classification_id');

    $this->actingAs($designer)->post(route('organization-design.services.store'), scServicePayload($organization, ['code' => 'SRV-OK', 'service_classification_id' => $local->id, 'cpc_classification_id' => $cpc->id]))
        ->assertRedirect();
    expect(Service::query()->where('code', 'SRV-OK')->firstOrFail()->service_classification_id)->toBe($local->id);
});

// 9 ─────────────────────────────────────────────────────────────────────────
test('a service cannot be justified by another organization\'s function', function (): void {
    $a = scOrganization('C1');
    $b = scOrganization('C2');
    $designer = scDesignerFor($a);
    $foreign = scFunction($b, 'F-B');

    $this->actingAs($designer)->from('/')->post(route('organization-design.services.store'), scServicePayload($a, ['function_ids' => [$foreign->id]]))
        ->assertSessionHasErrors('function_ids.0');
    expect(Service::query()->count())->toBe(0);
});

// 10 ────────────────────────────────────────────────────────────────────────
test('service version history is preserved when a new version is published', function (): void {
    $organization = scOrganization('D');
    $designer = scDesignerFor($organization);
    $approver = User::factory()->create();
    $approver->assignRole('Service Approver');

    $this->actingAs($designer)->post(route('organization-design.services.store'), scServicePayload($organization, ['code' => 'SRV-V']))->assertRedirect();
    $v1 = Service::query()->where('code', 'SRV-V')->firstOrFail();
    $this->actingAs($approver)->post(route('organization-design.services.publish', $v1))->assertRedirect();
    $this->actingAs($designer)->post(route('organization-design.services.versions.store', $v1), ['effective_from' => '2023-01-01', 'delivery_channel' => 'online'])->assertRedirect();
    $v2 = Service::query()->where('lineage_key', $v1->lineage_key)->where('version_no', 2)->firstOrFail();
    $this->actingAs($approver)->post(route('organization-design.services.publish', $v2))->assertRedirect();

    expect($v1->fresh()->status)->toBe(OrganizationDesignStatus::Superseded)
        ->and($v1->fresh()->effective_to?->toDateString())->toBe('2022-12-31')
        ->and($v2->fresh()->status)->toBe(OrganizationDesignStatus::Published)
        ->and($v2->delivery_channel)->toBe('online')
        ->and($v2->name_en)->toBe('Employee registration')
        ->and(Service::query()->where('lineage_key', $v1->lineage_key)->count())->toBe(2);
});

// 11 ────────────────────────────────────────────────────────────────────────
test('ISIC is an external reference on a service, never its code', function (): void {
    $organization = scOrganization('E');
    $designer = scDesignerFor($organization);
    $isic = IsicActivity::query()->create(['isic_code' => '8411', 'level' => 'class', 'section_code' => 'O', 'division_code' => '84', 'group_code' => '841', 'class_code' => '8411', 'name_en' => 'General public administration', 'revision' => 'Rev.4']);

    $this->actingAs($designer)->post(route('organization-design.services.store'), scServicePayload($organization, ['code' => 'SRV-ISIC', 'isic_activity_ids' => [$isic->id]]))->assertRedirect();
    $service = Service::query()->where('code', 'SRV-ISIC')->firstOrFail();

    expect($service->code)->toBe('SRV-ISIC')
        ->and($service->isicActivities()->pluck('isic_code')->all())->toBe(['8411'])
        ->and($service->isicActivities()->first()->revision)->toBe('Rev.4');
});

// 13 / 14 ───────────────────────────────────────────────────────────────────
test('a classification import keeps every revision, rejects bad codes and is idempotent', function (): void {
    $steward = User::factory()->create();
    $steward->assignRole('Classification Steward');
    $csv = "code,parent_code,level,name_en,name_am\nLGS-01,,1,Civil registration,የሲቪል ምዝገባ\nLGS-01-1,LGS-01,2,Birth registration,\nbad code!,,1,Broken,\n";
    $file = UploadedFile::fake()->createWithContent('local-2026.csv', $csv);

    // Dry run: nothing written, the bad row reported.
    $this->actingAs($steward)->post(route('organization-design.classifications.import'), ['scheme' => 'LOCAL', 'revision' => '2026', 'file' => $file, 'dry_run' => '1'])->assertRedirect();
    expect(ServiceClassification::query()->count())->toBe(0);
    $dry = ClassificationImportBatch::query()->latest('id')->firstOrFail();
    expect($dry->dry_run)->toBeTrue()->and($dry->failed_rows)->toBe(1)->and($dry->created_rows)->toBe(2);

    // Real import, twice: the second changes nothing.
    $file2 = UploadedFile::fake()->createWithContent('local-2026.csv', $csv);
    $this->actingAs($steward)->post(route('organization-design.classifications.import'), ['scheme' => 'LOCAL', 'revision' => '2026', 'file' => $file2])->assertRedirect();
    $file3 = UploadedFile::fake()->createWithContent('local-2026.csv', $csv);
    $this->actingAs($steward)->post(route('organization-design.classifications.import'), ['scheme' => 'LOCAL', 'revision' => '2026', 'file' => $file3])->assertRedirect();
    expect(ServiceClassification::query()->where('scheme', 'LOCAL')->where('revision', '2026')->count())->toBe(2)
        ->and(ServiceClassification::query()->where('code', 'LGS-01-1')->firstOrFail()->parent?->code)->toBe('LGS-01')
        ->and(ClassificationImportBatch::query()->latest('id')->firstOrFail()->created_rows)->toBe(0);

    // A later revision is a second set of rows; the earlier one stays.
    $file4 = UploadedFile::fake()->createWithContent('local-2027.csv', "code,parent_code,level,name_en,name_am\nLGS-01,,1,Civil registration (revised),\n");
    $this->actingAs($steward)->post(route('organization-design.classifications.import'), ['scheme' => 'LOCAL', 'revision' => '2027', 'file' => $file4])->assertRedirect();
    expect(ServiceClassification::query()->where('code', 'LGS-01')->count())->toBe(2)
        ->and(ServiceClassification::query()->where('code', 'LGS-01')->where('revision', '2026')->firstOrFail()->name_en)->toBe('Civil registration');
});

// 15 ────────────────────────────────────────────────────────────────────────
test('an institution designer cannot change a global classification', function (): void {
    $organization = scOrganization('F');
    $designer = scDesignerFor($organization);
    $local = scLocalClassification();

    $this->actingAs($designer)->post(route('organization-design.classifications.store'), ['scheme' => 'LOCAL', 'revision' => '2026', 'code' => 'LGS-02', 'level' => 1, 'name_en' => 'X'])->assertForbidden();
    $this->actingAs($designer)->put(route('organization-design.classifications.update', $local), ['name_en' => 'Tampered'])->assertForbidden();
    $this->actingAs($designer)->post(route('organization-design.classifications.import'), ['scheme' => 'LOCAL', 'revision' => '2026', 'file' => UploadedFile::fake()->createWithContent('x.csv', "code,parent_code,level,name_en,name_am\nLGS-03,,1,Y,\n")])->assertForbidden();
    $this->actingAs($designer)->get(route('organization-design.classifications.index'))->assertOk();
    expect($local->fresh()->name_en)->toBe('Civil registration');
});

test('a scoped designer only sees and writes services of their own organization', function (): void {
    $a = scOrganization('G1');
    $b = scOrganization('G2');
    $designerA = scDesignerFor($a);
    $serviceB = Service::query()->create([...scServicePayload($b, ['code' => 'SRV-B']), 'lineage_key' => (string) Str::uuid(), 'version_no' => 1, 'status' => OrganizationDesignStatus::Draft]);

    $this->actingAs($designerA)->get(route('organization-design.services.show', $serviceB))->assertForbidden();
    $this->actingAs($designerA)->post(route('organization-design.services.store'), scServicePayload($b))->assertForbidden();
    $this->actingAs($designerA)->get(route('organization-design.services.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('OrganizationDesign/Services/Index')->where('services.total', 0));
});
