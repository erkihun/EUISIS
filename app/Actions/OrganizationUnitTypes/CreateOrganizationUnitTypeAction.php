<?php

declare(strict_types=1);

namespace App\Actions\OrganizationUnitTypes;

use App\Actions\Audit\WriteAuditLogAction;
use App\Actions\CodeRules\GenerateCodeAction;
use App\Enums\AuditEventType;
use App\Enums\CodeRuleEntityType;
use App\Models\OrganizationUnitType;
use App\Models\User;
use Illuminate\Support\Facades\DB;

readonly class CreateOrganizationUnitTypeAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLogAction,
        private GenerateCodeAction $generateCodeAction,
    ) {}

    public function execute(array $attributes, User $actor): OrganizationUnitType
    {
        return DB::transaction(function () use ($attributes, $actor): OrganizationUnitType {
            $attributes['code'] = $this->generateCodeAction->execute(
                CodeRuleEntityType::OrganizationUnitType,
                [],
                $actor,
                $attributes['code'] ?? null,
                'code',
            );

            $attributes['created_by'] = $actor->getKey();
            $attributes['updated_by'] = $actor->getKey();

            $type = OrganizationUnitType::query()->create($attributes);

            $this->writeAuditLogAction->execute(
                AuditEventType::OrganizationUnitTypeCreated,
                $actor,
                $type,
                null,
                newValues: $type->toArray(),
            );

            return $type;
        });
    }
}
