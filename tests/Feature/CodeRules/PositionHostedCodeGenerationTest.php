<?php

declare(strict_types=1);

use App\Actions\Positions\CreatePositionAction;
use App\Enums\CodeRuleEntityType;
use App\Enums\CodeRuleResetFrequency;
use App\Enums\CodeRuleScopeStrategy;
use App\Enums\OrganizationRelationshipType;
use App\Enums\OrganizationStatus;
use App\Enums\RelationshipStatus;
use App\Enums\RelationshipTargetType;
use App\Models\CodeRule;
use App\Models\InstitutionOffice;
use App\Models\Occupation;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\OrganizationUnitRelationship;
use App\Models\Position;
use App\Models\User;
use App\Services\CodeGeneration\PositionCodeContextResolver;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;

/**
 * Job position codes composed from the owner organization code plus, when the
 * unit operates inside a host organization, the host organization code:
 *
 *   direct:  OWNER/SEQ      e.g. MA-01/01
 *   hosted:  OWNER/HOST/SEQ e.g. MA-01/K-01/01
 */
beforeEach(function (): void {
    $this->orgType = OrganizationType::query()->create([
        'code' => 'HCG-TYPE',
        'name_en' => 'Hosted Code Test Type',
    ]);

    $this->actor = User::factory()->create();

    // The seeded default rule for position codes (mirrors DatabaseSeeder).
    $this->rule = CodeRule::query()->create([
        'entity_type' => CodeRuleEntityType::Position->value,
        'scope_type' => null,
        'scope_id' => null,
        'name_en' => 'Job Position Code',
        'prefix' => 'POS',
        'suffix' => null,
        'format' => '{OWNER_ORG_CODE}/{HOST_ORG_CODE}/{SEQUENCE}',
        'separator' => '/',
        'sequence_length' => 2,
        'next_number' => 1,
        'initial_sequence_number' => 1,
        'sequence_scope_strategy' => CodeRuleScopeStrategy::Auto,
        'sequence_scope_tokens' => [],
        'reset_frequency' => CodeRuleResetFrequency::Never,
        'year_format' => 'Y',
        'is_active' => true,
        'allow_manual_override' => true,
        'require_approval_for_override' => false,
        'active_scope_key' => CodeRule::buildActiveScopeKey(CodeRuleEntityType::Position),
    ]);
});

function hcgOrganization(string $code, string $name, string $status = 'active'): Organization
{
    return Organization::query()->create([
        'organization_type_id' => test()->orgType->id,
        'code' => $code,
        'name_en' => $name,
        'status' => $status,
        'effective_from' => now()->toDateString(),
    ]);
}

/** A plain unit belonging to (and operating inside) its own organization. */
function hcgUnit(Organization $organization, string $code, string $name): OrganizationUnit
{
    return OrganizationUnit::query()->create([
        'organization_id' => $organization->id,
        'unit_type' => 'office',
        'code' => $code,
        'name_en' => $name,
        'status' => 'active',
    ]);
}

/**
 * A unit created under the HOST organization that belongs functionally to the
 * OWNER organization — the shape produced by the Institution Offices flow.
 */
function hcgHostedUnit(Organization $host, Organization $owner, string $code, string $name): OrganizationUnit
{
    $unit = hcgUnit($host, $code, $name);

    OrganizationUnitRelationship::query()->create([
        'source_unit_id' => $unit->id,
        'target_type' => RelationshipTargetType::Organization->value,
        'target_id' => $owner->id,
        'relationship_type' => OrganizationRelationshipType::FunctionalReporting->value,
        'is_primary' => true,
        'status' => RelationshipStatus::Active->value,
    ]);

    return $unit;
}

function hcgOccupation(): Occupation
{
    return Occupation::query()->firstOrCreate(
        ['isco_code' => '2422'],
        ['code' => 'OCC-2422', 'name_en' => 'Policy Professional', 'is_active' => true],
    );
}

function hcgCreatePosition(Organization $organization, OrganizationUnit $unit, string $title): Position
{
    return app(CreatePositionAction::class)->execute([
        'organization_id' => $organization->id,
        'organization_unit_id' => $unit->id,
        'occupation_id' => hcgOccupation()->id,
        'title_en' => $title,
        'is_active' => true,
        'effective_from' => now()->toDateString(),
    ], test()->actor);
}

// ─── Direct positions: OWNER/SEQ ─────────────────────────────────────────────

it('generates ownerCode/01 for the first position directly under the owner organization', function (): void {
    $bureau = hcgOrganization('MA-01', 'Public Service Bureau');
    $unit = hcgUnit($bureau, 'HR-UNIT', 'Human Resources');

    $position = hcgCreatePosition($bureau, $unit, 'HR Officer');

    expect($position->job_position_code)->toBe("{$bureau->code}/01");
});

it('generates ownerCode/02 for the second direct position', function (): void {
    $bureau = hcgOrganization('MA-01', 'Public Service Bureau');
    $unit = hcgUnit($bureau, 'HR-UNIT', 'Human Resources');

    $first = hcgCreatePosition($bureau, $unit, 'HR Officer I');
    $second = hcgCreatePosition($bureau, $unit, 'HR Officer II');

    expect($first->job_position_code)->toBe("{$bureau->code}/01")
        ->and($second->job_position_code)->toBe("{$bureau->code}/02");
});

// ─── Hosted positions: OWNER/HOST/SEQ ────────────────────────────────────────

it('generates ownerCode/hostCode/01 for a position in a unit hosted by another organization', function (): void {
    $bureau = hcgOrganization('MA-01', 'Public Service Bureau');
    $subCity = hcgOrganization('K-01', 'Bole Sub-city');
    $office = hcgHostedUnit($subCity, $bureau, 'PS-OFFICE', 'Public Service Office');

    $position = hcgCreatePosition($subCity, $office, 'Service Officer');

    expect($position->job_position_code)->toBe("{$bureau->code}/{$subCity->code}/01");
});

it('generates ownerCode/hostCode/02 for the second hosted position', function (): void {
    $bureau = hcgOrganization('MA-01', 'Public Service Bureau');
    $subCity = hcgOrganization('K-01', 'Bole Sub-city');
    $office = hcgHostedUnit($subCity, $bureau, 'PS-OFFICE', 'Public Service Office');

    $first = hcgCreatePosition($subCity, $office, 'Service Officer I');
    $second = hcgCreatePosition($subCity, $office, 'Service Officer II');

    expect($first->job_position_code)->toBe("{$bureau->code}/{$subCity->code}/01")
        ->and($second->job_position_code)->toBe("{$bureau->code}/{$subCity->code}/02");
});

it('keeps hosted and direct sequences independent for the same owner', function (): void {
    $bureau = hcgOrganization('MA-01', 'Public Service Bureau');
    $subCity = hcgOrganization('K-01', 'Bole Sub-city');
    $directUnit = hcgUnit($bureau, 'HR-UNIT', 'Human Resources');
    $office = hcgHostedUnit($subCity, $bureau, 'PS-OFFICE', 'Public Service Office');

    $direct1 = hcgCreatePosition($bureau, $directUnit, 'HR Officer I');
    $hosted1 = hcgCreatePosition($subCity, $office, 'Service Officer I');
    $direct2 = hcgCreatePosition($bureau, $directUnit, 'HR Officer II');

    expect($direct1->job_position_code)->toBe("{$bureau->code}/01")
        ->and($hosted1->job_position_code)->toBe("{$bureau->code}/{$subCity->code}/01")
        ->and($direct2->job_position_code)->toBe("{$bureau->code}/02");
});

it('gives each host organization its own independent sequence', function (): void {
    $bureau = hcgOrganization('MA-01', 'Public Service Bureau');
    $bole = hcgOrganization('K-01', 'Bole Sub-city');
    $yeka = hcgOrganization('K-02', 'Yeka Sub-city');
    $boleOffice = hcgHostedUnit($bole, $bureau, 'PS-OFFICE-BOLE', 'Public Service Office Bole');
    $yekaOffice = hcgHostedUnit($yeka, $bureau, 'PS-OFFICE-YEKA', 'Public Service Office Yeka');

    $bole1 = hcgCreatePosition($bole, $boleOffice, 'Officer Bole I');
    $bole2 = hcgCreatePosition($bole, $boleOffice, 'Officer Bole II');
    $yeka1 = hcgCreatePosition($yeka, $yekaOffice, 'Officer Yeka I');

    expect($bole1->job_position_code)->toBe("{$bureau->code}/{$bole->code}/01")
        ->and($bole2->job_position_code)->toBe("{$bureau->code}/{$bole->code}/02")
        ->and($yeka1->job_position_code)->toBe("{$bureau->code}/{$yeka->code}/01");
});

// ─── Child units inherit the hosted rule ─────────────────────────────────────

it('applies the hosted rule to positions in a child unit of a hosted office', function (): void {
    $bureau = hcgOrganization('MA-01', 'Public Service Bureau');
    $subCity = hcgOrganization('K-01', 'Bole Sub-city');
    $office = hcgHostedUnit($subCity, $bureau, 'PS-OFFICE', 'Public Service Office');

    $childUnit = OrganizationUnit::query()->create([
        'organization_id' => $subCity->id,
        'parent_unit_id' => $office->id,
        'unit_type' => 'team',
        'code' => 'PS-TEAM',
        'name_en' => 'Records Team',
        'status' => 'active',
    ]);

    $position = hcgCreatePosition($subCity, $childUnit, 'Records Officer');

    expect($position->job_position_code)->toBe("{$bureau->code}/{$subCity->code}/01");
});

it('applies the hosted rule to positions in a grandchild unit of a hosted office', function (): void {
    $bureau = hcgOrganization('MA-01', 'Public Service Bureau');
    $subCity = hcgOrganization('K-01', 'Bole Sub-city');
    $office = hcgHostedUnit($subCity, $bureau, 'PS-OFFICE', 'Public Service Office');

    $childUnit = OrganizationUnit::query()->create([
        'organization_id' => $subCity->id,
        'parent_unit_id' => $office->id,
        'unit_type' => 'department',
        'code' => 'PS-DEPT',
        'name_en' => 'Service Department',
        'status' => 'active',
    ]);

    $grandchildUnit = OrganizationUnit::query()->create([
        'organization_id' => $subCity->id,
        'parent_unit_id' => $childUnit->id,
        'unit_type' => 'team',
        'code' => 'PS-DESK',
        'name_en' => 'Front Desk',
        'status' => 'active',
    ]);

    $position = hcgCreatePosition($subCity, $grandchildUnit, 'Front Desk Officer');

    expect($position->job_position_code)->toBe("{$bureau->code}/{$subCity->code}/01");
});

it('continues one owner/host sequence across the office and its child units', function (): void {
    $bureau = hcgOrganization('MA-01', 'Public Service Bureau');
    $subCity = hcgOrganization('K-01', 'Bole Sub-city');
    $office = hcgHostedUnit($subCity, $bureau, 'PS-OFFICE', 'Public Service Office');

    $childUnit = OrganizationUnit::query()->create([
        'organization_id' => $subCity->id,
        'parent_unit_id' => $office->id,
        'unit_type' => 'team',
        'code' => 'PS-TEAM',
        'name_en' => 'Records Team',
        'status' => 'active',
    ]);

    $inOffice = hcgCreatePosition($subCity, $office, 'Service Officer');
    $inChild = hcgCreatePosition($subCity, $childUnit, 'Records Officer');

    expect($inOffice->job_position_code)->toBe("{$bureau->code}/{$subCity->code}/01")
        ->and($inChild->job_position_code)->toBe("{$bureau->code}/{$subCity->code}/02");
});

it('keeps child units of non-hosted units on the direct owner-only format', function (): void {
    $bureau = hcgOrganization('MA-01', 'Public Service Bureau');
    $unit = hcgUnit($bureau, 'HR-UNIT', 'Human Resources');

    $childUnit = OrganizationUnit::query()->create([
        'organization_id' => $bureau->id,
        'parent_unit_id' => $unit->id,
        'unit_type' => 'team',
        'code' => 'HR-TEAM',
        'name_en' => 'Recruitment Team',
        'status' => 'active',
    ]);

    $position = hcgCreatePosition($bureau, $childUnit, 'Recruiter');

    expect($position->job_position_code)->toBe("{$bureau->code}/01");
});

// ─── Validation ──────────────────────────────────────────────────────────────

it('rejects a duplicate manual position code via the store endpoint', function (): void {
    Permission::findOrCreate('positions.create', 'web');
    $this->actor->givePermissionTo('positions.create');

    $bureau = hcgOrganization('MA-01', 'Public Service Bureau');
    $unit = hcgUnit($bureau, 'HR-UNIT', 'Human Resources');
    $existing = hcgCreatePosition($bureau, $unit, 'HR Officer');

    $this->actingAs($this->actor)
        ->post(route('positions.store'), [
            'job_position_code' => $existing->job_position_code,
            'title_en' => 'Duplicate Officer',
            'organization_id' => $bureau->id,
            'organization_unit_id' => $unit->id,
            'occupation_id' => hcgOccupation()->id,
            'is_active' => true,
        ])
        ->assertSessionHasErrors('job_position_code');
});

it('refuses to generate a code when the owner organization is inactive', function (): void {
    $bureau = hcgOrganization('MA-01', 'Public Service Bureau', OrganizationStatus::Inactive->value);
    $unit = hcgUnit($bureau, 'HR-UNIT', 'Human Resources');

    expect(fn () => hcgCreatePosition($bureau, $unit, 'HR Officer'))
        ->toThrow(ValidationException::class);
});

it('refuses to generate a code when the host organization is inactive', function (): void {
    $bureau = hcgOrganization('MA-01', 'Public Service Bureau');
    $subCity = hcgOrganization('K-01', 'Bole Sub-city', OrganizationStatus::Inactive->value);
    $office = hcgHostedUnit($subCity, $bureau, 'PS-OFFICE', 'Public Service Office');

    expect(fn () => hcgCreatePosition($subCity, $office, 'Service Officer'))
        ->toThrow(ValidationException::class);
});

it('refuses to generate a code when the owner of a hosted unit is inactive', function (): void {
    $bureau = hcgOrganization('MA-01', 'Public Service Bureau', OrganizationStatus::Inactive->value);
    $subCity = hcgOrganization('K-01', 'Bole Sub-city');
    $office = hcgHostedUnit($subCity, $bureau, 'PS-OFFICE', 'Public Service Office');

    expect(fn () => hcgCreatePosition($subCity, $office, 'Service Officer'))
        ->toThrow(ValidationException::class);
});

// ─── Context resolution ──────────────────────────────────────────────────────

it('treats a unit whose functional owner equals its own organization as not hosted', function (): void {
    $bureau = hcgOrganization('MA-01', 'Public Service Bureau');
    // Owner relationship pointing at the unit's own organization — no host segment.
    $unit = hcgHostedUnit($bureau, $bureau, 'SELF-UNIT', 'Self Office');

    $resolved = app(PositionCodeContextResolver::class)->resolve($bureau->id, $unit->id);

    expect($resolved['owner_organization_id'])->toBe($bureau->id)
        ->and($resolved['host_organization_id'])->toBeNull();
});

it('resolves the owner from the institution office fallback when no relationship exists', function (): void {
    $bureau = hcgOrganization('MA-01', 'Public Service Bureau');
    $subCity = hcgOrganization('K-01', 'Bole Sub-city');

    $office = InstitutionOffice::query()->create([
        'institution_id' => $bureau->id,
        'office_level' => 'sub_city',
        'office_code' => 'IO-PS-BOLE',
        'name_en' => 'Public Service Office Bole',
        'status' => 'active',
    ]);

    $unit = hcgUnit($subCity, 'PS-OFFICE', 'Public Service Office');
    $unit->update(['institution_office_id' => $office->id]);

    $position = hcgCreatePosition($subCity, $unit, 'Service Officer');

    expect($position->job_position_code)->toBe("{$bureau->code}/{$subCity->code}/01");
});
