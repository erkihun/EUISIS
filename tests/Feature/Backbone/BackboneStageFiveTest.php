<?php

declare(strict_types=1);

use App\Backbone\Adapters\NoEmployeeFeedbackLinks;
use App\Backbone\Adapters\NoProviderCodeLookup;
use App\Backbone\Contracts\EmployeeFeedbackLinks;
use App\Backbone\Contracts\ProviderCodeLookup;
use App\Enums\CodeRuleEntityType;
use App\Enums\CodeRuleResetFrequency;
use App\Enums\CodeRuleScopeStrategy;
use App\Enums\EmployeeStatus;
use App\Models\CodeRule;
use App\Models\Employee;
use App\Models\EmployeeServiceFeedback;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\TransferApplication;
use App\Services\CodeGeneration\CodeGeneratorService;
use App\Services\CodeGeneration\CodeRuleTargetRegistry;
use App\Services\Organizations\OrganizationDeletionGuard;
use App\Services\ServiceFeedback\EmployeeFeedbackLinkAdapter;
use Illuminate\Support\Str;
use Modules\Entitlements\Application\Services\ServiceProviderCodeLookup;
use Modules\Entitlements\Domain\Models\ServiceProvider;
use Modules\Entitlements\Domain\Models\ServiceType;
use Modules\Grievance\Domain\Models\Grievance;
use Modules\Grievance\Domain\Models\GrievanceCategory;

/*
 * Stage 5 of the master backbone separation: the backbone's registries are
 * filled by the modules that own the data (ServiceModuleServiceProvider), and
 * the backbone reaches feedback links and provider codes through contracts.
 */

function stageFiveRule(CodeRuleEntityType $type, string $format, array $overrides = []): CodeRule
{
    // Some entity types already have a seeded rule; the one active rule per type is replaced.
    return CodeRule::query()->updateOrCreate(['active_scope_key' => CodeRule::buildActiveScopeKey($type)], [
        'entity_type' => $type->value, 'scope_type' => null, 'scope_id' => null, 'name_en' => 'Stage five '.$type->value,
        'prefix' => 'S5', 'format' => $format, 'separator' => '-', 'sequence_length' => 4, 'next_number' => 1, 'initial_sequence_number' => 1,
        'sequence_scope_strategy' => CodeRuleScopeStrategy::Global, 'sequence_scope_tokens' => [], 'reset_frequency' => CodeRuleResetFrequency::Never,
        'year_format' => 'Y', 'is_active' => true, 'allow_manual_override' => false, 'require_approval_for_override' => false, ...$overrides,
    ]);
}

it('keeps every collision check the generator had, now registered by the owning module', function (): void {
    $registry = app(CodeRuleTargetRegistry::class);
    $expected = [
        // Backbone entity types, listed by the registry itself.
        'organization' => ['App\Models\Organization', 'code', true],
        'organization_type' => ['App\Models\OrganizationType', 'code', true],
        'employee' => ['App\Models\Employee', 'employee_number', false],
        'position' => ['App\Models\Position', 'job_position_code', true],
        'employee_position' => ['App\Models\Position', 'job_position_code', true],
        'id_card' => ['App\Models\IdCard', 'card_number', false],
        'service_type' => ['Modules\Entitlements\Domain\Models\ServiceType', 'code', true],
        'organization_unit' => ['App\Models\OrganizationUnit', 'code', true],
        'organization_unit_type' => ['App\Models\OrganizationUnitType', 'code', true],
        // Service-module entity types, registered by ServiceModuleServiceProvider.
        'service_provider' => [ServiceProvider::class, 'code', false],
        'transfer_application' => [TransferApplication::class, 'application_number', true],
        'public_service_complaint' => [EmployeeServiceFeedback::class, 'reference_number', false],
        'public_tip_off' => [EmployeeServiceFeedback::class, 'reference_number', false],
        'disciplinary_case' => ['Modules\Discipline\Domain\Models\DisciplinaryCase', 'case_number', false],
        // Grievance numbers were listed in the wrong match and never checked (fixed here).
        'grievance_case' => [Grievance::class, 'reference_number', true],
        'grievance_decision' => ['Modules\Grievance\Domain\Models\GrievanceDecision', 'decision_no', true],
        'grievance_letter' => ['Modules\Grievance\Domain\Models\GrievanceLetter', 'reference_number', true],
    ];

    foreach ($expected as $type => [$model, $column, $withTrashed]) {
        expect($registry->collisionCheck($type))->toBe(['model' => $model, 'column' => $column, 'with_trashed' => $withTrashed], $type);
    }
    expect($registry->get(CodeRuleEntityType::ServiceProvider)['table'])->toBe('service_providers')
        ->and($registry->get(CodeRuleEntityType::TransferApplication)['code_column'])->toBe('application_number')
        ->and($registry->isImmutableAfterCreate(CodeRuleEntityType::TransferApplication))->toBeTrue();
});

it('skips a grievance number that is already taken instead of failing on insert', function (): void {
    $type = OrganizationType::query()->create(['code' => 'S5-T', 'name_en' => 'Stage five type']);
    $organization = Organization::query()->create(['organization_type_id' => $type->id, 'code' => 'S5-ORG', 'name_en' => 'Stage five', 'status' => 'active']);
    $category = GrievanceCategory::query()->create(['code' => 'S5-CAT', 'name_en' => 'Working conditions', 'name_am' => 'የሥራ ሁኔታ', 'is_active' => true]);
    Grievance::query()->create([
        'reference_number' => 'GRV-0001', 'organization_id' => $organization->id, 'origin_level' => 'organization',
        'category_id' => $category->id, 'subject' => 'Taken number', 'description' => 'Already holds the first number.',
    ]);

    $code = app(CodeGeneratorService::class)->generate(stageFiveRule(CodeRuleEntityType::GrievanceCase, 'GRV-{SEQUENCE}'));

    expect($code)->toBe('GRV-0002');
});

it('resolves provider code tokens through the backbone contract', function (): void {
    app()->instance(ProviderCodeLookup::class, new class implements ProviderCodeLookup
    {
        public function codeFor(string $providerId): ?string
        {
            return 'CAFE7';
        }
    });

    $preview = app(CodeGeneratorService::class)->preview(stageFiveRule(CodeRuleEntityType::CafeteriaTransaction, '{PROVIDER_CODE}-{SEQUENCE}'), ['service_provider_id' => (string) Str::uuid()]);

    expect($preview)->toBe('CAFE7-0001');
});

it('binds the module adapters over the backbone defaults', function (): void {
    expect(app(EmployeeFeedbackLinks::class))->toBeInstanceOf(EmployeeFeedbackLinkAdapter::class)
        ->and(app(ProviderCodeLookup::class))->toBeInstanceOf(ServiceProviderCodeLookup::class)
        ->and((new NoEmployeeFeedbackLinks)->publicUrlFor(new Employee))->toBeNull()
        ->and((new NoProviderCodeLookup)->codeFor((string) Str::uuid()))->toBeNull();
});

it('provisions and reads an employee feedback link through the contract', function (): void {
    $employee = Employee::query()->create(['employee_number' => 'S5-EMP', 'first_name' => 'Stage', 'last_name' => 'Five', 'full_name' => 'Stage Five', 'status' => EmployeeStatus::Active->value]);
    $links = app(EmployeeFeedbackLinks::class);

    expect($links->publicUrlFor($employee))->toBeNull();
    $links->provision($employee);
    expect($links->publicUrlFor($employee))->toStartWith(rtrim(url('/'), '/'));
});

it('blocks deleting an organization that a service module references, through the registered tables', function (): void {
    $dependents = OrganizationDeletionGuard::dependents();
    expect($dependents)->toHaveKeys(['organization_change_requests', 'institution_offices', 'service_providers', 'transfer_applications', 'grievances', 'cafeteria_providers', 'transport_routes'])
        ->and($dependents['transfer_applications'])->toBe(['releasing_organization_id', 'receiving_organization_id']);

    $type = OrganizationType::query()->create(['code' => 'S5-T2', 'name_en' => 'Stage five type']);
    $organization = Organization::query()->create(['organization_type_id' => $type->id, 'code' => 'S5-DEL', 'name_en' => 'Referenced', 'status' => 'active']);
    expect(app(OrganizationDeletionGuard::class)->reasons($organization))->toBe([]);

    $serviceType = ServiceType::query()->firstOrCreate(['code' => 'cafeteria'], ['name_en' => 'Cafeteria', 'name_am' => 'ካፍቴሪያ']);
    ServiceProvider::query()->create(['organization_id' => $organization->id, 'service_type_id' => $serviceType->id, 'name' => 'Canteen', 'code' => 'S5-SP', 'status' => 'active']);
    expect(app(OrganizationDeletionGuard::class)->reasons($organization))->toBe(['hasOtherReferences']);
});
