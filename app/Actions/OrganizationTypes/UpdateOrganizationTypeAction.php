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

            $oldCode = $type->code;
            $type->update($attributes);

            // Other types name their allowed parents by code; follow a renamed code
            // so their registration rules keep working.
            if ($type->code !== $oldCode) {
                OrganizationType::query()
                    ->whereKeyNot($type->id)
                    ->get()
                    ->filter(fn (OrganizationType $other): bool => in_array($oldCode, $other->parent_allowed_types ?? [], true))
                    ->each(fn (OrganizationType $other) => $other->update([
                        'parent_allowed_types' => array_values(array_map(
                            fn (string $code): string => $code === $oldCode ? $type->code : $code,
                            $other->parent_allowed_types,
                        )),
                    ]));
            }

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
