<?php

declare(strict_types=1);

use App\Enums\OrganizationDesignStatus;
use App\Enums\OrganizationScopeType;
use App\Enums\OrganizationStatus;
use App\Models\InstitutionMandate;
use App\Models\LegalInstrumentType;
use App\Models\Organization;
use App\Models\OrganizationFunction;
use App\Models\OrganizationType;
use App\Models\User;
use App\Models\UserOrganizationScope;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
 * Organization Design & Workforce Planning, phase 1 (docs/organization-design-workforce-planning-plan.md):
 * mandates, powers and functions. Brief tests 1–5.
 */

beforeEach(function (): void {
    foreach ([
        'institution-mandates.viewAny', 'institution-mandates.view', 'institution-mandates.create',
        'institution-mandates.update', 'institution-mandates.approve',
        'organization-functions.viewAny', 'organization-functions.manage',
    ] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    Role::findOrCreate('Super Admin', 'web');
    Role::findOrCreate('Design Officer', 'web')->syncPermissions([
        'institution-mandates.viewAny', 'institution-mandates.view', 'institution-mandates.create', 'institution-mandates.update',
        'organization-functions.viewAny', 'organization-functions.manage',
    ]);
    Role::findOrCreate('Design Approver', 'web')->syncPermissions([
        'institution-mandates.viewAny', 'institution-mandates.view', 'institution-mandates.approve',
    ]);
});

function odOrganization(string $suffix): Organization
{
    $type = OrganizationType::query()->firstOrCreate(['code' => 'OD-BUREAU'], ['name_en' => 'OD Bureau', 'is_demo' => false]);

    return Organization::query()->create([
        'organization_type_id' => $type->id, 'code' => 'OD-'.$suffix.'-'.uniqid(), 'name_en' => 'OD Org '.$suffix,
        'status' => OrganizationStatus::Active, 'effective_from' => now()->subYear()->toDateString(), 'is_demo' => false,
    ]);
}

/** A designer confined to one organization. */
function odOfficerFor(Organization $organization): User
{
    $user = User::factory()->create();
    $user->assignRole('Design Officer');
    UserOrganizationScope::query()->create([
        'user_id' => $user->id, 'organization_id' => $organization->id, 'scope_type' => OrganizationScopeType::Self,
        'is_active' => true, 'effective_from' => now()->subDay()->toDateString(),
    ]);

    return $user->fresh();
}

function odApprover(): User
{
    $user = User::factory()->create();
    $user->assignRole('Design Approver');

    return $user;
}

function odInstrumentType(): LegalInstrumentType
{
    return LegalInstrumentType::query()->firstOrCreate(['code' => 'PROCLAMATION'], ['name_en' => 'Proclamation', 'name_am' => 'አዋጅ', 'sort_order' => 10]);
}

/** @return array<string, mixed> */
function odMandatePayload(Organization $organization, array $overrides = []): array
{
    return [
        'organization_id' => $organization->id,
        'code' => 'MND-'.substr(uniqid(), -5),
        'title_en' => 'Establishment Proclamation',
        'title_am' => 'የማቋቋሚያ አዋጅ',
        'legal_instrument_type_id' => odInstrumentType()->id,
        'legal_instrument_number' => '1234/2020',
        'legal_reference' => 'Proclamation 1234/2020',
        'effective_from' => '2021-01-01',
        ...$overrides,
    ];
}

function odPublishedMandate(Organization $organization, User $officer): InstitutionMandate
{
    test()->actingAs($officer)->post(route('organization-design.mandates.store'), odMandatePayload($organization))->assertRedirect();
    $mandate = InstitutionMandate::query()->where('organization_id', $organization->id)->firstOrFail();
    test()->actingAs(odApprover())->post(route('organization-design.mandates.publish', $mandate))->assertRedirect();

    return $mandate->fresh();
}

// 1 ─────────────────────────────────────────────────────────────────────────
test('an organization can have versioned mandates: a new version supersedes the published one on its effective date', function (): void {
    $organization = odOrganization('A');
    $officer = odOfficerFor($organization);
    $v1 = odPublishedMandate($organization, $officer);

    expect($v1->status)->toBe(OrganizationDesignStatus::Published)
        ->and($v1->version_no)->toBe(1)
        ->and($v1->approved_by)->not->toBeNull();

    $this->actingAs($officer)->post(route('organization-design.mandates.versions.store', $v1), [
        'effective_from' => '2024-07-08', 'legal_instrument_number' => '1300/2024',
    ])->assertRedirect();

    $v2 = InstitutionMandate::query()->where('lineage_key', $v1->lineage_key)->where('version_no', 2)->firstOrFail();
    expect($v2->status)->toBe(OrganizationDesignStatus::Draft)
        ->and($v2->code)->toBe($v1->code)
        ->and($v2->title_en)->toBe($v1->title_en)
        ->and($v2->legal_instrument_number)->toBe('1300/2024');

    $this->actingAs(odApprover())->post(route('organization-design.mandates.publish', $v2))->assertRedirect();

    $v1->refresh();
    $v2->refresh();
    expect($v1->status)->toBe(OrganizationDesignStatus::Superseded)
        ->and($v1->effective_to?->toDateString())->toBe('2024-07-07')
        ->and($v2->status)->toBe(OrganizationDesignStatus::Published)
        ->and(InstitutionMandate::query()->where('lineage_key', $v1->lineage_key)->count())->toBe(2);
});

// 2 ─────────────────────────────────────────────────────────────────────────
test('a published mandate cannot be destructively edited', function (): void {
    $organization = odOrganization('B');
    $officer = odOfficerFor($organization);
    $mandate = odPublishedMandate($organization, $officer);

    $this->actingAs($officer)->from(route('organization-design.mandates.show', $mandate))
        ->put(route('organization-design.mandates.update', $mandate), ['title_en' => 'Rewritten'])
        ->assertSessionHasErrors('status');

    expect($mandate->fresh()->title_en)->toBe('Establishment Proclamation');
});

test('the officer who drafted a mandate cannot publish it, and publishing needs the approve permission', function (): void {
    $organization = odOrganization('B2');
    $officer = odOfficerFor($organization);
    $this->actingAs($officer)->post(route('organization-design.mandates.store'), odMandatePayload($organization))->assertRedirect();
    $mandate = InstitutionMandate::query()->firstOrFail();

    $this->actingAs($officer)->post(route('organization-design.mandates.publish', $mandate))->assertForbidden();

    $approver = odApprover();
    $approver->givePermissionTo('institution-mandates.create');
    $this->actingAs($approver)->post(route('organization-design.mandates.store'), odMandatePayload($organization, ['code' => 'OWN-1']))->assertRedirect();
    $own = InstitutionMandate::query()->where('code', 'OWN-1')->firstOrFail();
    $this->actingAs($approver)->from('/')->post(route('organization-design.mandates.publish', $own))->assertSessionHasErrors('status');
    expect($own->fresh()->status)->toBe(OrganizationDesignStatus::Draft);
});

// 3 ─────────────────────────────────────────────────────────────────────────
test('a function requires a mandate of its own organization', function (): void {
    $a = odOrganization('C1');
    $b = odOrganization('C2');
    $officer = odOfficerFor($a);
    $mandateB = InstitutionMandate::query()->create([
        ...odMandatePayload($b, ['code' => 'MND-B']), 'lineage_key' => (string) \Illuminate\Support\Str::uuid(), 'version_no' => 1,
        'status' => OrganizationDesignStatus::Published,
    ]);
    $mandateA = odPublishedMandate($a, $officer);

    $this->actingAs($officer)->from('/')->post(route('organization-design.mandates.functions.store', $mandateA), [
        'code' => 'F-1', 'title_en' => 'Register employees', 'mandate_id' => $mandateB->id,
    ])->assertSessionHasErrors('mandate_id');

    $this->actingAs($officer)->post(route('organization-design.mandates.functions.store', $mandateA), [
        'code' => 'F-1', 'title_en' => 'Register employees', 'legal_reference' => 'Art. 5(1)',
    ])->assertRedirect();

    $function = OrganizationFunction::query()->where('code', 'F-1')->firstOrFail();
    expect($function->organization_id)->toBe($a->id)
        ->and($function->mandate_id)->toBe($mandateA->id)
        ->and($function->depth)->toBe(0)
        ->and($function->path)->toBe($function->id);
});

// 4 ─────────────────────────────────────────────────────────────────────────
test('a scoped designer cannot read or write another organization\'s mandates', function (): void {
    $a = odOrganization('D1');
    $b = odOrganization('D2');
    $officerA = odOfficerFor($a);
    $mandateB = odPublishedMandate($b, odOfficerFor($b));

    $this->actingAs($officerA)->get(route('organization-design.mandates.show', $mandateB))->assertForbidden();
    $this->actingAs($officerA)->post(route('organization-design.mandates.store'), odMandatePayload($b))->assertForbidden();
    $this->actingAs($officerA)->post(route('organization-design.mandates.functions.store', $mandateB), [
        'code' => 'F-X', 'title_en' => 'Intrude',
    ])->assertForbidden();

    $this->actingAs($officerA)->get(route('organization-design.mandates.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('OrganizationDesign/Mandates/Index')->where('mandates.total', 0));
});

// 5 ─────────────────────────────────────────────────────────────────────────
test('the function tree cannot cycle and moving a branch rewrites descendant paths', function (): void {
    $organization = odOrganization('E');
    $officer = odOfficerFor($organization);
    $mandate = odPublishedMandate($organization, $officer);

    $store = fn (string $code, ?string $parent = null) => $this->actingAs($officer)
        ->post(route('organization-design.mandates.functions.store', $mandate), ['code' => $code, 'title_en' => $code, 'parent_function_id' => $parent])
        ->assertRedirect();
    $store('ROOT');
    $root = OrganizationFunction::query()->where('code', 'ROOT')->firstOrFail();
    $store('CHILD', $root->id);
    $child = OrganizationFunction::query()->where('code', 'CHILD')->firstOrFail();
    $store('GRANDCHILD', $child->id);
    $grandchild = OrganizationFunction::query()->where('code', 'GRANDCHILD')->firstOrFail();

    expect($grandchild->depth)->toBe(2)
        ->and($grandchild->path)->toBe("{$root->id}/{$child->id}/{$grandchild->id}");

    // Root under its own grandchild: a cycle.
    $this->actingAs($officer)->from('/')->post(route('organization-design.functions.move', $root), ['parent_function_id' => $grandchild->id])
        ->assertSessionHasErrors('parent_function_id');
    // A function under itself.
    $this->actingAs($officer)->from('/')->post(route('organization-design.functions.move', $child), ['parent_function_id' => $child->id])
        ->assertSessionHasErrors('parent_function_id');

    // Child to the top: the grandchild follows.
    $this->actingAs($officer)->post(route('organization-design.functions.move', $child), ['parent_function_id' => null])->assertRedirect();
    expect($child->fresh()->depth)->toBe(0)
        ->and($grandchild->fresh()->path)->toBe("{$child->id}/{$grandchild->id}")
        ->and($grandchild->fresh()->depth)->toBe(1);

    $this->actingAs($officer)->get(route('organization-design.functions.index', ['organization_id' => $organization->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('OrganizationDesign/Functions/Index')->has('tree', 2));
});
