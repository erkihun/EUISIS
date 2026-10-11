<?php

declare(strict_types=1);

namespace App\Services\CodeGeneration;

use App\Enums\CodeRuleEntityType;
use App\Models\Employee;
use App\Models\IdCard;
use App\Models\InstitutionMandate;
use App\Models\OrganizationFunction;
use App\Models\OrganizationPower;
use App\Models\Process;
use App\Models\ProcessActivity;
use App\Models\ProcessTask;
use App\Models\Service;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\OrganizationUnitType;
use App\Models\Position;
use App\Models\ServiceProvider;
use App\Models\ServiceType;
use App\Models\TransferApplication;

/**
 * Central registry mapping entity_type values to their table metadata.
 *
 * Each entry describes:
 *  - model: the Eloquent model class
 *  - table: database table name
 *  - code_column: column that holds the business code
 *  - label: human-readable label for error messages
 *  - immutable_after_create: whether the code can never be changed after initial creation
 */
class CodeRuleTargetRegistry
{
    /**
     * @return array<string, array{model: class-string, table: string, code_column: string, label: string, immutable_after_create: bool}>
     */
    public function all(): array
    {
        return [
            CodeRuleEntityType::Organization->value => [
                'model' => Organization::class,
                'table' => 'organizations',
                'code_column' => 'code',
                'label' => 'Organization Code',
                'immutable_after_create' => false,
            ],
            CodeRuleEntityType::InstitutionMandate->value => [
                'model' => InstitutionMandate::class,
                'table' => 'institution_mandates',
                'code_column' => 'code',
                'label' => 'Institution Mandate Code',
                'immutable_after_create' => true,
            ],
            CodeRuleEntityType::OrganizationPower->value => [
                'model' => OrganizationPower::class,
                'table' => 'organization_powers',
                'code_column' => 'code',
                'label' => 'Organization Power Code',
                'immutable_after_create' => false,
            ],
            CodeRuleEntityType::OrganizationFunction->value => [
                'model' => OrganizationFunction::class,
                'table' => 'organization_functions',
                'code_column' => 'code',
                'label' => 'Organization Function Code',
                'immutable_after_create' => false,
            ],
            CodeRuleEntityType::Service->value => [
                'model' => Service::class,
                'table' => 'services',
                'code_column' => 'code',
                'label' => 'Service Code',
                'immutable_after_create' => true,
            ],
            CodeRuleEntityType::Process->value => [
                'model' => Process::class,
                'table' => 'processes',
                'code_column' => 'code',
                'label' => 'Process Code',
                'immutable_after_create' => true,
            ],
            CodeRuleEntityType::ProcessActivity->value => [
                'model' => ProcessActivity::class,
                'table' => 'process_activities',
                'code_column' => 'code',
                'label' => 'Activity Code',
                'immutable_after_create' => false,
            ],
            CodeRuleEntityType::ProcessTask->value => [
                'model' => ProcessTask::class,
                'table' => 'process_tasks',
                'code_column' => 'code',
                'label' => 'Master Task Code',
                'immutable_after_create' => false,
            ],
            CodeRuleEntityType::OrganizationType->value => [
                'model' => OrganizationType::class,
                'table' => 'organization_types',
                'code_column' => 'code',
                'label' => 'Organization Type Code',
                'immutable_after_create' => false,
            ],
            CodeRuleEntityType::OrganizationUnit->value => [
                'model' => OrganizationUnit::class,
                'table' => 'organization_units',
                'code_column' => 'code',
                'label' => 'Organization Unit Code',
                'immutable_after_create' => false,
            ],
            CodeRuleEntityType::OrganizationUnitType->value => [
                'model' => OrganizationUnitType::class,
                'table' => 'organization_unit_types',
                'code_column' => 'code',
                'label' => 'Organization Unit Type Code',
                'immutable_after_create' => false,
            ],
            CodeRuleEntityType::Employee->value => [
                'model' => Employee::class,
                'table' => 'employees',
                'code_column' => 'employee_number',
                'label' => 'Employee Number',
                'immutable_after_create' => true,
            ],
            CodeRuleEntityType::Position->value => [
                'model' => Position::class,
                'table' => 'positions',
                'code_column' => 'job_position_code',
                'label' => 'Job Position Code',
                'immutable_after_create' => false,
            ],
            CodeRuleEntityType::IdCard->value => [
                'model' => IdCard::class,
                'table' => 'id_cards',
                'code_column' => 'card_number',
                'label' => 'ID Card Number',
                'immutable_after_create' => true,
            ],
            CodeRuleEntityType::ServiceProvider->value => [
                'model' => ServiceProvider::class,
                'table' => 'service_providers',
                'code_column' => 'code',
                'label' => 'Service Provider Code',
                'immutable_after_create' => false,
            ],
            CodeRuleEntityType::ServiceType->value => [
                'model' => ServiceType::class,
                'table' => 'service_types',
                'code_column' => 'code',
                'label' => 'Service Type Code',
                'immutable_after_create' => false,
            ],
            CodeRuleEntityType::TransferApplication->value => [
                'model' => TransferApplication::class,
                'table' => 'transfer_applications',
                'code_column' => 'application_number',
                'label' => 'Transfer Application Number',
                'immutable_after_create' => true,
            ],
        ];
    }

    /**
     * @return array{model: class-string, table: string, code_column: string, label: string, immutable_after_create: bool}|null
     */
    public function get(CodeRuleEntityType|string $entityType): ?array
    {
        $key = $entityType instanceof CodeRuleEntityType ? $entityType->value : $entityType;

        return $this->all()[$key] ?? null;
    }

    public function isImmutableAfterCreate(CodeRuleEntityType|string $entityType): bool
    {
        return $this->get($entityType)['immutable_after_create'] ?? false;
    }
}
