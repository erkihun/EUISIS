<?php

declare(strict_types=1);

namespace App\Actions\OrganizationUnitTypes;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\OrganizationUnitType;
use App\Models\User;
use Illuminate\Support\Facades\DB;

readonly class UpdateOrganizationUnitTypeAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLogAction,
    ) {}

    public function execute(array $attributes, OrganizationUnitType $type, User $actor): OrganizationUnitType
    {
        return DB::transaction(function () use ($attributes, $type, $actor): OrganizationUnitType {
            $old = $type->toArray();

            $attributes['updated_by'] = $actor->getKey();

            $type->update($attributes);

            $this->writeAuditLogAction->execute(
                AuditEventType::OrganizationUnitTypeUpdated,
                $actor,
                $type,
                null,
                oldValues: $old,
                newValues: $type->fresh()?->toArray(),
            );

            return $type->fresh() ?? $type;
        });
    }
}
