<?php

declare(strict_types=1);

namespace App\Services\CodeGeneration;

use App\Enums\CodeRuleEntityType;
use App\Models\Employee;
use App\Models\IdCard;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\OrganizationUnitType;
use App\Models\Position;

/**
 * Which table and column hold the code of each code-rule entity type: the
 * EUISIS registry of the same name, limited to the master data the Core owns.
 * The generator looks here before handing out a code, so a code already in
 * use (imported data) is skipped instead of failing on insert.
 */
class CodeRuleTargetRegistry
{
    /** @var array<string, array{model: class-string, table: string, code_column: string, label: string, immutable_after_create: bool}> */
    private static array $moduleTargets = [];

    /** @var array<string, array{model: class-string, column: string, with_trashed: bool}> */
    private static array $moduleCollisionChecks = [];

    /** @param array{model: class-string, table: string, code_column: string, label: string, immutable_after_create: bool} $target */
    public static function registerTarget(CodeRuleEntityType $entityType, array $target): void
    {
        self::$moduleTargets[$entityType->value] = $target;
    }

    /** @param class-string $model */
    public static function registerCollisionCheck(CodeRuleEntityType $entityType, string $model, string $column, bool $withTrashed = false): void
    {
        self::$moduleCollisionChecks[$entityType->value] = ['model' => $model, 'column' => $column, 'with_trashed' => $withTrashed];
    }

    /** @return array<string, array{model: class-string, table: string, code_column: string, label: string, immutable_after_create: bool}> */
    public function all(): array
    {
        return self::targets() + self::$moduleTargets;
    }

    /** @return array{model: class-string, table: string, code_column: string, label: string, immutable_after_create: bool}|null */
    public function get(CodeRuleEntityType|string $entityType): ?array
    {
        return $this->all()[$entityType instanceof CodeRuleEntityType ? $entityType->value : $entityType] ?? null;
    }

    public function isImmutableAfterCreate(CodeRuleEntityType|string $entityType): bool
    {
        return (bool) ($this->get($entityType)['immutable_after_create'] ?? false);
    }

    /** @return array{model: class-string, column: string, with_trashed: bool}|null */
    public function collisionCheck(CodeRuleEntityType|string $entityType): ?array
    {
        $value = $entityType instanceof CodeRuleEntityType ? $entityType->value : $entityType;
        $check = fn (string $model, string $column, bool $withTrashed): array => ['model' => $model, 'column' => $column, 'with_trashed' => $withTrashed];

        return ([
            CodeRuleEntityType::Organization->value => $check(Organization::class, 'code', true),
            CodeRuleEntityType::OrganizationType->value => $check(OrganizationType::class, 'code', true),
            CodeRuleEntityType::OrganizationUnit->value => $check(OrganizationUnit::class, 'code', true),
            CodeRuleEntityType::OrganizationUnitType->value => $check(OrganizationUnitType::class, 'code', true),
            CodeRuleEntityType::Employee->value => $check(Employee::class, 'employee_number', false),
            CodeRuleEntityType::Position->value => $check(Position::class, 'job_position_code', true),
            CodeRuleEntityType::EmployeePosition->value => $check(Position::class, 'job_position_code', true),
            CodeRuleEntityType::IdCard->value => $check(IdCard::class, 'card_number', false),
        ] + self::$moduleCollisionChecks)[$value] ?? null;
    }

    /** The entity types the Core numbers, in the order the form offers them. @return list<CodeRuleEntityType> */
    public static function entityTypes(): array
    {
        return array_map(fn (string $value): CodeRuleEntityType => CodeRuleEntityType::from($value), array_keys(self::targets()));
    }

    /** @return array<string, array{model: class-string, table: string, code_column: string, label: string, immutable_after_create: bool}> */
    private static function targets(): array
    {
        $target = fn (string $model, string $table, string $column, string $label, bool $immutable): array => [
            'model' => $model, 'table' => $table, 'code_column' => $column, 'label' => $label, 'immutable_after_create' => $immutable,
        ];

        return [
            CodeRuleEntityType::Organization->value => $target(Organization::class, 'organizations', 'code', 'Organization Code', false),
            CodeRuleEntityType::OrganizationType->value => $target(OrganizationType::class, 'organization_types', 'code', 'Organization Type Code', false),
            CodeRuleEntityType::OrganizationUnit->value => $target(OrganizationUnit::class, 'organization_units', 'code', 'Organization Unit Code', false),
            CodeRuleEntityType::OrganizationUnitType->value => $target(OrganizationUnitType::class, 'organization_unit_types', 'code', 'Organization Unit Type Code', false),
            CodeRuleEntityType::Employee->value => $target(Employee::class, 'employees', 'employee_number', 'Employee Number', true),
            CodeRuleEntityType::Position->value => $target(Position::class, 'positions', 'job_position_code', 'Job Position Code', false),
            CodeRuleEntityType::IdCard->value => $target(IdCard::class, 'id_cards', 'card_number', 'ID Card Number', true),
        ];
    }
}
