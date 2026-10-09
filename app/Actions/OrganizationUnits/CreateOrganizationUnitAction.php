<?php

declare(strict_types=1);

namespace App\Actions\OrganizationUnits;

use App\Actions\Audit\WriteAuditLogAction;
use App\Actions\CodeRules\GenerateCodeAction;
use App\Enums\AuditEventType;
use App\Enums\CodeRuleEntityType;
use App\Models\OrganizationUnit;
use App\Models\User;

readonly class CreateOrganizationUnitAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLogAction,
        private GenerateCodeAction $generateCodeAction,
    ) {}

    public function execute(array $attributes, User $actor): OrganizationUnit
    {
        $attributes['code'] = $this->generateCodeAction->execute(
            CodeRuleEntityType::OrganizationUnit,
            [
                'organization_id' => $attributes['organization_id'] ?? null,
                'organization_unit_type_id' => $attributes['organization_unit_type_id'] ?? null,
            ],
            $actor,
            $attributes['code'] ?? null,
            'code',
        );

        $attributes['created_by'] = $actor->getKey();
        $attributes['updated_by'] = $actor->getKey();

        $unit = OrganizationUnit::query()->create($attributes);

        $this->writeAuditLogAction->execute(
            AuditEventType::OrganizationUnitCreated,
            $actor,
            $unit,
            $unit->organization_id,
            newValues: $unit->toArray(),
        );

        return $unit;
    }
}
