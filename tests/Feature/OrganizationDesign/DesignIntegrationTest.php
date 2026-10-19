<?php

declare(strict_types=1);

use App\Enums\OrganizationDesignStatus;
use App\Enums\OrganizationScopeType;
use App\Enums\OrganizationStatus;
use App\Models\DesignDocumentSuggestion;
use App\Models\InstitutionMandate;
use App\Models\LegalInstrumentType;
use App\Models\ManpowerPlan;
use App\Models\ManpowerPlanLine;
use App\Models\Occupation;
use App\Models\Organization;
use App\Models\OrganizationFunction;
use App\Models\OrganizationPower;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\OrganizationUnitType;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\UserOrganizationScope;
use App\Models\WorkforcePlanningMethodology;
use App\Services\Intelligence\AI\AiResult;
use App\Services\Intelligence\AI\Contracts\LLMGateway;
use App\Services\OrganizationDesign\WorkforcePlanningService;
use App\Services\SystemSettings\SystemSettingsService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\FakeLLMGateway;

/*
 * Phase E (docs/organization-design-legacy-baseline-plan.md): document
 * suggestions that only suggest, approved-headcount override evidence that
 * never touches the calculated FTE.
 */

beforeEach(function (): void {
    config(['cache.default' => 'array']);
    foreach ([...array_column(require base_path('database/seeders/data/organization-design-permissions.php'), 'name')] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    Role::findOrCreate('Super Admin', 'web');
    Role::findOrCreate('DS Designer', 'web')->syncPermissions(['institution-mandates.viewAny', 'institution-mandates.view', 'institution-mandates.create', 'institution-mandates.update', 'organization-functions.viewAny', 'organization-functions.manage', 'design-suggestions.view', 'design-suggestions.apply']);
    Role::findOrCreate('DS Reader', 'web')->syncPermissions(['institution-mandates.viewAny', 'institution-mandates.view', 'design-suggestions.view']);
    LegalInstrumentType::query()->firstOrCreate(['code' => 'PROCLAMATION'], ['name_en' => 'Proclamation', 'sort_order' => 10]);
});

function dsOrganization(): Organization
{
    $type = OrganizationType::query()->firstOrCreate(['code' => 'DS-BUREAU'], ['name_en' => 'DS Bureau', 'is_demo' => false]);

    return Organization::query()->create(['organization_type_id' => $type->id, 'code' => 'DS-'.uniqid(), 'name_en' => 'DS Org', 'status' => OrganizationStatus::Active, 'effective_from' => '2010-01-01', 'is_demo' => false]);
}

function dsUser(Organization $organization, string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);
    UserOrganizationScope::query()->create(['user_id' => $user->id, 'organization_id' => $organization->id, 'scope_type' => OrganizationScopeType::Self, 'is_active' => true, 'effective_from' => now()->subDay()->toDateString()]);

    return $user->fresh();
}

function dsMandate(Organization $organization): InstitutionMandate
{
    return InstitutionMandate::query()->create(['organization_id' => $organization->id, 'lineage_key' => (string) Str::uuid(), 'version_no' => 1, 'code' => 'MND-'.substr(uniqid(), -4), 'title_en' => 'Mandate', 'legal_instrument_type_id' => LegalInstrumentType::query()->value('id'), 'legal_instrument_number' => '1/2015', 'effective_from' => '2015-01-01', 'status' => OrganizationDesignStatus::Draft]);
}

function dsAllowKnowledgeOutbound(): void
{
    SystemSetting::query()->updateOrCreate(['group' => 'intelligence', 'key' => 'ai_outbound_data_classes'], ['value' => 'aggregates,knowledge_documents', 'type' => 'string', 'label_en' => 'x', 'label_am' => 'x']);
    app(SystemSettingsService::class)->clearCache();
}

test('a passage yields suggestions only; applying one records a function with the person\'s code, rejecting leaves nothing', function (): void {
    dsAllowKnowledgeOutbound();
    $this->app->instance(LLMGateway::class, new FakeLLMGateway(new AiResult(ok: true, json: ['items' => [
        ['title' => 'Issue professional licences', 'kind' => 'function', 'legal_reference' => 'Art. 5(1)', 'uncertain' => false],
        ['title' => 'Inspect premises', 'kind' => 'power', 'legal_reference' => 'Art. 5(2)', 'uncertain' => true],
        ['title' => '   ', 'kind' => 'function', 'legal_reference' => '', 'uncertain' => false],
    ], 'notes' => ''], runId: 'run-x')));
    $organization = dsOrganization();
    $designer = dsUser($organization, 'DS Designer');
    $mandate = dsMandate($organization);

    $this->actingAs($designer)->post(route('organization-design.mandates.suggestions.store', $mandate), ['passage' => 'Article 5. The Bureau shall: (1) issue professional licences; (2) inspect premises.'])->assertSessionHasNoErrors();
    $suggestion = DesignDocumentSuggestion::query()->firstOrFail();
    expect($suggestion->status)->toBe('ready')->and($suggestion->items)->toHaveCount(2)
        ->and($suggestion->items[1]['uncertain'])->toBeTrue()
        ->and(OrganizationFunction::query()->where('mandate_id', $mandate->id)->count())->toBe(0)
        // The passage is not kept, only its fingerprint.
        ->and($suggestion->getAttributes())->not->toHaveKey('passage');

    $this->actingAs(dsUser($organization, 'DS Reader'))->post(route('organization-design.suggestions.apply', [$suggestion, 0]), ['code' => 'F-1'])->assertForbidden();
    $this->actingAs($designer)->post(route('organization-design.suggestions.apply', [$suggestion, 0]), ['code' => 'F-LIC', 'title_am' => 'የሙያ ፈቃድ መስጠት'])->assertSessionHasNoErrors();
    $function = OrganizationFunction::query()->where('mandate_id', $mandate->id)->firstOrFail();
    expect($function->code)->toBe('F-LIC')->and($function->title_en)->toBe('Issue professional licences')->and($function->legal_reference)->toBe('Art. 5(1)');

    $this->actingAs($designer)->post(route('organization-design.suggestions.apply', [$suggestion, 0]), ['code' => 'F-AGAIN'])->assertSessionHasErrors('item');
    $this->actingAs($designer)->post(route('organization-design.suggestions.reject', [$suggestion, 1]))->assertSessionHasNoErrors();
    expect($suggestion->fresh()->items[1]['state'])->toBe('rejected')->and(OrganizationPower::query()->where('mandate_id', $mandate->id)->count())->toBe(0);
});

test('with the gateway off the suggestion is "unavailable" and nothing else happens', function (): void {
    $this->app->instance(LLMGateway::class, new FakeLLMGateway(new AiResult(ok: false, reason: 'unavailable'), isAvailable: false));
    $organization = dsOrganization();
    $designer = dsUser($organization, 'DS Designer');
    $mandate = dsMandate($organization);

    $this->actingAs($designer)->post(route('organization-design.mandates.suggestions.store', $mandate), ['passage' => 'Article 5. The Bureau shall issue licences.'])->assertSessionHasNoErrors();
    expect(DesignDocumentSuggestion::query()->value('status'))->toBe('unavailable')->and(OrganizationFunction::query()->count())->toBe(0);
    // Another organization's designer cannot ask on this mandate.
    $this->actingAs(dsUser(dsOrganization(), 'DS Designer'))->post(route('organization-design.mandates.suggestions.store', $mandate), ['passage' => 'x'])->assertForbidden();
});

test('an approved-headcount override records who, when and on what reference, and the calculated FTE stays as calculated', function (): void {
    $organization = dsOrganization();
    $planner = User::factory()->create();
    $unitType = OrganizationUnitType::query()->firstOrCreate(['code' => 'DS-DIR'], ['name_en' => 'Directorate', 'is_active' => true]);
    $unit = OrganizationUnit::query()->create(['organization_id' => $organization->id, 'organization_unit_type_id' => $unitType->id, 'code' => 'U-1', 'name_en' => 'Unit', 'unit_type' => 'directorate', 'status' => 'active']);
    $methodology = WorkforcePlanningMethodology::query()->create(['lineage_key' => (string) Str::uuid(), 'version_no' => 1, 'code' => 'FIX', 'name_en' => 'Fixed', 'calculation_type' => 'FIXED_MINIMUM', 'parameters' => ['minimum_positions' => '1'], 'rounding_rule' => 'ceil', 'status' => OrganizationDesignStatus::Published, 'effective_from' => '2024-01-01']);
    $plan = ManpowerPlan::query()->create(['reference_number' => 'MPP-'.uniqid(), 'organization_id' => $organization->id, 'methodology_id' => $methodology->id, 'title_en' => 'Plan', 'planning_period_start' => '2027-01-01', 'planning_period_end' => '2027-12-31', 'status' => OrganizationDesignStatus::Draft, 'prepared_by' => $planner->id]);
    $occupation = Occupation::query()->create(['code' => 'DS-OCC', 'isco_code' => '2422', 'name_en' => 'Policy officer', 'is_active' => true]);
    $line = ManpowerPlanLine::query()->create(['plan_id' => $plan->id, 'organization_id' => $organization->id, 'organization_unit_id' => $unit->id, 'occupation_id' => $occupation->id, 'inputs' => [], 'calculated_fte' => '7.3500', 'recommended_headcount' => 8, 'sequence' => 1]);
    $service = app(WorkforcePlanningService::class);

    expect(fn () => $service->updateLine($planner, $line, ['approved_headcount' => 7]))->toThrow(ValidationException::class);
    $service->updateLine($planner, $line->fresh(), ['approved_headcount' => 7, 'adjustment_reason' => 'Budget ceiling', 'adjustment_reference' => 'Minute 4/2027']);
    $line->refresh();
    expect($line->approved_headcount)->toBe(7)->and($line->calculated_fte)->toBe('7.3500')->and($line->recommended_headcount)->toBe(8)
        ->and($line->adjusted_by)->toBe($planner->id)->and($line->adjusted_at)->not->toBeNull()->and($line->adjustment_reference)->toBe('Minute 4/2027');
});
