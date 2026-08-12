<?php

declare(strict_types=1);

namespace App\Actions\OrganizationTypes;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\OrganizationType;
use App\Models\User;
use Illuminate\Support\Facades\DB;

readonly class UpdateOrganizationTypeAction
{
    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    public function execute(array $attributes, OrganizationType $type, User $actor): OrganizationType
    {
        return DB::transaction(function () use ($attributes, $type, $actor): OrganizationType {
            $oldValues = $type->only(['code', 'prefix', 'name_en', 'name_am', 'description_en', 'description_am', 'is_active', 'sort_order', 'level_order', 'category', 'parent_allowed_types']);

            $type->update($attributes);

            $this->writeAuditLogAction->execute(
                AuditEventType::OrganizationTypeUpdated,
                $actor,
                $type,
                null,
                oldValues: $oldValues,
                newValues: $type->fresh()->toArray(),
            );

            return $type->fresh();
        });
    }
}
