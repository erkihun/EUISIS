<?php

declare(strict_types=1);

namespace App\Actions\OrganizationUnits;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\OrganizationUnit;
use App\Models\User;

readonly class UpdateOrganizationUnitAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLogAction,
    ) {}

    public function execute(OrganizationUnit $unit, array $attributes, User $actor): OrganizationUnit
    {
        $old = $unit->toArray();

        $attributes['updated_by'] = $actor->getKey();

        $unit->update($attributes);

        $this->writeAuditLogAction->execute(
            AuditEventType::OrganizationUnitUpdated,
            $actor,
            $unit,
            $unit->organization_id,
            oldValues: $old,
            newValues: $unit->fresh()?->toArray(),
        );

        return $unit->fresh() ?? $unit;
    }
}
