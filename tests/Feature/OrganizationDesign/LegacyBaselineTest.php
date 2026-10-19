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
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
 * Legacy Organization Baseline (docs/organization-design-legacy-baseline-plan.md,
 * ADR-LB-002/003): the wizard's steps, the validation gate, maker ≠ checker,
 * the transactional publication with its snapshot, supersession and revision.
 */

beforeEach(function (): void {
    foreach (array_column(require base_path('database/seeders/data/organization-design-permissions.php'), 'name') as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    Role::findOrCreate('Super Admin', 'web');
    Role::findOrCreate('LB Designer', 'web')->syncPermissions(['legacy-baselines.viewAny', 'legacy-baselines.view', 'legacy-baselines.create', 'legacy-baselines.update', 'legal-sources.viewAny', 'legal-sources.view']);
    Role::findOrCreate('LB Approver', 'web')->syncPermissions(['legacy-baselines.viewAny', 'legacy-baselines.view', 'legacy-baselines.review', 'legacy-baselines.approve', 'legacy-baselines.publish']);
    LegalInstrumentType::query()->firstOrCreate(['code' => 'PROCLAMATION'], ['name_en' => 'Proclamation', 'name_am' => 'አዋጅ', 'sort_order' => 10]);
});

function lbOrganization(string $suffix): Organization
{
    $type = OrganizationType::query()->firstOrCreate(['code' => 'LB-BUREAU'], ['name_en' => 'LB Bureau', 'is_demo' => false]);

    return Organization::query()->create([
        'organization_type_id' => $type->id, 'code' => 'LB-'.$suffix.'-'.uniqid(), 'name_en' => 'LB Org '.$suffix,
        'status' => OrganizationStatus::Active, 'effective_from' => now()->subYear()->toDateString(), 'is_demo' => false,
    ]);
}

function lbUser(Organization $organization, string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);
    UserOrganizationScope::query()->create(['user_id' => $user->id, 'organization_id' => $organization->id, 'scope_type' => OrganizationScopeType::Self, 'is_active' => true, 'effective_from' => now()->subDay()->toDateString()]);

    return $user->fresh();
}

/** A legacy institution: one published mandate, two units, two positions and a legal source. */
function lbLegacyInstitution(Organization $organization, User $by): LegalSource
{
    $source = LegalSource::query()->create(['organization_id' => $organization->id, 'code' => 'PROC-'.substr(uniqid(), -4), 'title_en' => 'Establishment proclamation', 'source_type_id' => LegalInstrumentType::query()->value('id'), 'status' => 'active', 'created_by' => $by->id]);
    InstitutionMandate::query()->create(['organization_id' => $organization->id, 'lineage_key' => (string) Str::uuid(), 'version_no' => 1, 'code' => 'MND-'.substr(uniqid(), -4), 'title_en' => 'Mandate', 'legal_instrument_type_id' => $source->source_type_id, 'legal_instrument_number' => '1/2015', 'legal_source_id' => $source->id, 'effective_from' => '2015-01-01', 'status' => OrganizationDesignStatus::Published, 'approved_by' => $by->id, 'approved_at' => now()]);
    $unitType = OrganizationUnitType::query()->firstOrCreate(['code' => 'LB-DIR'], ['name_en' => 'Directorate', 'is_active' => true]);
    $parent = OrganizationUnit::query()->create(['organization_id' => $organization->id, 'organization_unit_type_id' => $unitType->id, 'code' => 'U-'.substr(uniqid(), -4), 'name_en' => 'HR Directorate', 'unit_type' => 'directorate', 'status' => 'active']);
    $child = OrganizationUnit::query()->create(['organization_id' => $organization->id, 'organization_unit_type_id' => $unitType->id, 'parent_unit_id' => $parent->id, 'code' => 'U-'.substr(uniqid(), -4), 'name_en' => 'Records Team', 'unit_type' => 'team', 'status' => 'active']);
    Position::query()->create(['organization_id' => $organization->id, 'organization_unit_id' => $parent->id, 'job_position_code' => 'P-'.substr(uniqid(), -4), 'title_en' => 'Director', 'grade_level' => 'XII', 'is_active' => true]);
    Position::query()->create(['organization_id' => $organization->id, 'organization_unit_id' => $child->id, 'job_position_code' => 'P-'.substr(uniqid(), -4), 'title_en' => 'Records Officer', 'grade_level' => 'IX', 'is_active' => true]);

    return $source;
}

test('a baseline detects the records the institution already has, lets a person annotate a step, and keeps the draft open', function (): void {
    $organization = lbOrganization('A');
    $designer = lbUser($organization, 'LB Designer');
    lbLegacyInstitution($organization, $designer);

    $this->actingAs($designer)->post(route('organization-design.legacy-baselines.store'), ['organization_id' => $organization->id, 'title_en' => 'Baseline 2015', 'effective_date' => '2015-07-01', 'origin' => 'manual'])
        ->assertSessionHasNoErrors()->assertRedirect();
    $baseline = LegacyBaseline::query()->where('organization_id', $organization->id)->firstOrFail();
    expect($baseline->reference_number)->toStartWith('LOB-'.now()->format('Y').'-')
        ->and($baseline->status)->toBe(OrganizationDesignStatus::Draft)
        ->and($baseline->steps()->count())->toBe(22);

    $steps = $baseline->steps()->get()->keyBy('step_key');
    expect($steps['mandates']->record_count)->toBe(1)->and($steps['mandates']->status)->toBe('complete')
        ->and($steps['unit_design']->record_count)->toBe(2)->and($steps['positions']->record_count)->toBe(2)
        ->and($steps['services']->status)->toBe('incomplete')->and($steps['publish']->status)->toBe('incomplete');

    // "This institution has no lower offices" is something only a person can say.
    $this->actingAs($designer)->put(route('organization-design.legacy-baselines.steps.save', [$baseline, 'structure_version']), ['status' => 'complete', 'notes' => 'Bureau only; no sub-city offices'])->assertSessionHasNoErrors();
    $this->actingAs($designer)->put(route('organization-design.legacy-baselines.steps.save', [$baseline, 'publish']), ['status' => 'complete'])->assertSessionHasErrors('step');
    $step = $baseline->steps()->where('step_key', 'structure_version')->firstOrFail();
    expect($step->status)->toBe('complete')->and($step->status_source)->toBe('manual');

    // A later refresh keeps the hand-set status.
    app(LegacyBaselineService::class)->refreshSteps($baseline);
    expect($baseline->steps()->where('step_key', 'structure_version')->value('status'))->toBe('complete');

    $this->actingAs($designer)->get(route('organization-design.legacy-baselines.show', $baseline))->assertOk();
    $this->actingAs(lbUser(lbOrganization('B'), 'LB Designer'))->get(route('organization-design.legacy-baselines.show', $baseline))->assertForbidden();
});

test('validation blocks submission until the legal source and effective date exist, and errors never block an incomplete draft from being saved', function (): void {
    $organization = lbOrganization('C');
    $designer = lbUser($organization, 'LB Designer');
    $source = lbLegacyInstitution($organization, $designer);
    $service = app(LegacyBaselineService::class);

    $baseline = $service->create($designer, $organization->id, ['title_en' => 'Baseline', 'origin' => 'manual']);
    $report = $service->validate($designer, $baseline);
    $codes = collect($report['findings'])->pluck('code');
    expect($report['ok'])->toBeFalse()
        ->and($codes)->toContain('baseline_without_legal_source', 'baseline_without_effective_date')
        ->and($baseline->fresh()->validation_status)->toBe('failed');

    $this->actingAs($designer)->post(route('organization-design.legacy-baselines.submit', $baseline))->assertSessionHasErrors('status');
    expect($baseline->fresh()->status)->toBe(OrganizationDesignStatus::Draft);

    $service->update($designer, $baseline, ['legal_source_id' => $source->id, 'effective_date' => '2015-07-01']);
    $report = $service->validate($designer, $baseline);
    expect($report['ok'])->toBeTrue()->and($baseline->fresh()->validation_status)->toBeIn(['passed', 'warnings']);
});

test('the preparer cannot approve or publish; publication freezes a snapshot in one transaction and supersedes the earlier baseline; a revision drafts from the published one', function (): void {
    $organization = lbOrganization('D');
    $designer = lbUser($organization, 'LB Designer');
    $approver = lbUser($organization, 'LB Approver');
    $source = lbLegacyInstitution($organization, $designer);
    $service = app(LegacyBaselineService::class);

    $first = $service->create($designer, $organization->id, ['title_en' => 'Baseline v1', 'origin' => 'manual', 'legal_source_id' => $source->id, 'effective_date' => '2015-07-01']);
    $this->actingAs($designer)->post(route('organization-design.legacy-baselines.submit', $first))->assertSessionHasNoErrors();
    expect($first->fresh()->status)->toBe(OrganizationDesignStatus::UnderReview);

    // The designer holds no approve permission; a designer who did would still be refused as the preparer.
    $this->actingAs($designer)->post(route('organization-design.legacy-baselines.approve', $first))->assertForbidden();
    expect(fn () => $service->approve($designer, $first->fresh(), null))->toThrow(ValidationException::class);

    $this->actingAs($approver)->post(route('organization-design.legacy-baselines.return', $first), ['reason' => 'Name the hierarchy version'])->assertSessionHasNoErrors();
    expect($first->fresh()->status)->toBe(OrganizationDesignStatus::Draft)->and($first->fresh()->review_comment)->toBe('Name the hierarchy version');
    $this->actingAs($designer)->post(route('organization-design.legacy-baselines.submit', $first))->assertSessionHasNoErrors();
    $this->actingAs($approver)->post(route('organization-design.legacy-baselines.approve', $first), ['approval_reference' => 'Minute 12/2026'])->assertSessionHasNoErrors();
    expect($first->fresh()->status)->toBe(OrganizationDesignStatus::Approved)->and($first->fresh()->approval_reference)->toBe('Minute 12/2026');

    $this->actingAs($approver)->post(route('organization-design.legacy-baselines.publish', $first))->assertSessionHasNoErrors();
    $first->refresh();
    expect($first->status)->toBe(OrganizationDesignStatus::Published)->and($first->snapshot)->not->toBeNull()
        ->and($first->snapshot->counts['units'])->toBe(2)->and($first->snapshot->counts['positions'])->toBe(2)->and($first->snapshot->counts['mandates'])->toBe(1)
        ->and(strlen($first->snapshot->sha256))->toBe(64);
    $this->actingAs($designer)->get(route('organization-design.legacy-baselines.snapshot', $first))->assertOk()->assertJsonPath('counts.units', 2);

    // Published is immutable.
    $this->actingAs($designer)->put(route('organization-design.legacy-baselines.update', $first), ['title_en' => 'Changed'])->assertSessionHasErrors('status');
    expect($first->fresh()->title_en)->toBe('Baseline v1');

    // A revision drafts from the published one; publishing it supersedes v1 and leaves v1's snapshot untouched.
    $this->actingAs($designer)->post(route('organization-design.legacy-baselines.revisions.store', $first))->assertSessionHasNoErrors();
    $second = LegacyBaseline::query()->where('lineage_key', $first->lineage_key)->where('version_no', 2)->firstOrFail();
    expect($second->status)->toBe(OrganizationDesignStatus::Draft)->and($second->legal_source_id)->toBe($source->id);
    $this->actingAs($designer)->post(route('organization-design.legacy-baselines.revisions.store', $first))->assertSessionHasErrors('status');

    $service->submit($designer, $second);
    $service->approve($approver, $second->fresh(), 'Minute 13/2026');
    $service->publish($approver, $second->fresh());
    expect($first->fresh()->status)->toBe(OrganizationDesignStatus::Superseded)->and($first->fresh()->superseded_by_id)->toBe($second->id)
        ->and($first->fresh()->snapshot->counts['units'])->toBe(2)
        ->and($second->fresh()->status)->toBe(OrganizationDesignStatus::Published);
});
