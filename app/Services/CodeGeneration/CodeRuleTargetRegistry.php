<?php

declare(strict_types=1);

namespace App\Services\CodeGeneration;

use App\Enums\CodeRuleEntityType;
use App\Models\Employee;
use App\Models\IdCard;
use App\Models\InstitutionMandate;
use App\Models\Organization;
use App\Models\OrganizationFunction;
use App\Models\OrganizationPower;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\OrganizationUnitType;
use App\Models\Position;
use App\Models\Process;
use App\Models\ProcessActivity;
use App\Models\ProcessTask;
use App\Models\Service;
use App\Models\ServiceType;

/**
 * Which table and column hold the code of each code-rule entity type.
 *
 * Two lists live here:
 *  - targets: the table metadata a manual code override is checked against
 *    (model, table, code_column, label, immutable_after_create);
 *  - collision checks: where the generator looks before handing out a code,
 *    so a code already in use is skipped instead of failing on insert.
 *
 * The backbone's own entity types are listed below. Service modules register
 * theirs from ServiceModuleServiceProvider, so this class never depends on a
 * service module (docs/architecture/backbone-refactor-migration.md, Stage 5).
 */
class CodeRuleTargetRegistry
{
    /** @var array<string, array{model: class-string, table: string, code_column: string, label: string, immutable_after_create: bool}> */
    private static array $moduleTargets = [];

    /** @var array<string, array{model: class-string, column: string, with_trashed: bool}> */
    private static array $moduleCollisionChecks = [];

    /**
     * A service module's entity type, for manual overrides.
     *
     * @param  array{model: class-string, table: string, code_column: string, label: string, immutable_after_create: bool}  $target
     */
    public static function registerTarget(CodeRuleEntityType $entityType, array $target): void
    {
        self::$moduleTargets[$entityType->value] = $target;
    }

    /** Where a service module's codes live, for the generator's collision check. @param class-string $model */
    public static function registerCollisionCheck(CodeRuleEntityType $entityType, string $model, string $column, bool $withTrashed = false): void
    {
        self::$moduleCollisionChecks[$entityType->value] = ['model' => $model, 'column' => $column, 'with_trashed' => $withTrashed];
    }

    /**
     * @return array<string, array{model: class-string, table: string, code_column: string, label: string, immutable_after_create: bool}>
     */
    public function all(): array
    {
        return [...$this->coreTargets(), ...self::$moduleTargets];
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

    /**
     * Where the generator checks that a code is free; null when the entity
     * type has no check (the table's own unique index is the guard).
     *
     * @return array{model: class-string, column: string, with_trashed: bool}|null
     */
    public function collisionCheck(CodeRuleEntityType|string $entityType): ?array
    {
        $key = $entityType instanceof CodeRuleEntityType ? $entityType->value : $entityType;

        return [...$this->coreCollisionChecks(), ...self::$moduleCollisionChecks][$key] ?? null;
    }

    /** @return array<string, array{model: class-string, column: string, with_trashed: bool}> */
    private function coreCollisionChecks(): array
    {
        $check = fn (string $model, string $column, bool $withTrashed): array => ['model' => $model, 'column' => $column, 'with_trashed' => $withTrashed];

        return [
            CodeRuleEntityType::Organization->value => $check(Organization::class, 'code', true),
            CodeRuleEntityType::OrganizationType->value => $check(OrganizationType::class, 'code', true),
            CodeRuleEntityType::Employee->value => $check(Employee::class, 'employee_number', false),
            CodeRuleEntityType::Position->value => $check(Position::class, 'job_position_code', true),
            CodeRuleEntityType::EmployeePosition->value => $check(Position::class, 'job_position_code', true),
            CodeRuleEntityType::IdCard->value => $check(IdCard::class, 'card_number', false),
            CodeRuleEntityType::ServiceType->value => $check(ServiceType::class, 'code', true),
            CodeRuleEntityType::OrganizationUnit->value => $check(OrganizationUnit::class, 'code', true),
            CodeRuleEntityType::OrganizationUnitType->value => $check(OrganizationUnitType::class, 'code', true),
        ];
    }

    /** @return array<string, array{model: class-string, table: string, code_column: string, label: string, immutable_after_create: bool}> */
    private function coreTargets(): array
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
            CodeRuleEntityType::ServiceType->value => [
                'model' => ServiceType::class,
                'table' => 'service_types',
                'code_column' => 'code',
                'label' => 'Service Type Code',
                'immutable_after_create' => false,
            ],
        ];
    }
}
