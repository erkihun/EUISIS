<?php

declare(strict_types=1);

namespace App\Actions\OrganizationTypes;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

readonly class DeleteOrganizationTypeAction
{
    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    public function execute(OrganizationType $type, User $actor): void
    {
        $this->ensureUnused($type);

        DB::transaction(function () use ($type, $actor): void {
            $type->deleted_by = $actor->id;
            $type->save();
            $type->delete();

            $this->writeAuditLogAction->execute(
                AuditEventType::OrganizationTypeDeleted,
                $actor,
                $type,
                null,
            );
        });
    }

    /**
     * A type that organizations still carry, or that another type names as an
     * allowed parent, stays: deleting it would leave those records pointing at
     * a type the system no longer shows. Deactivate it instead.
     */
    private function ensureUnused(OrganizationType $type): void
    {
        $organizations = Organization::withTrashed()->where('organization_type_id', $type->id)->count();

        if ($organizations > 0) {
            throw ValidationException::withMessages([
                'organization_type' => __('organization-types.cannot_delete_in_use', ['count' => $organizations]),
            ]);
        }

        $referencedBy = OrganizationType::query()
            ->whereKeyNot($type->id)
            ->get(['id', 'code', 'name_en', 'parent_allowed_types'])
            ->filter(fn (OrganizationType $other): bool => in_array($type->code, $other->parent_allowed_types ?? [], true))
            ->pluck('name_en');

        if ($referencedBy->isNotEmpty()) {
            throw ValidationException::withMessages([
                'organization_type' => __('organization-types.cannot_delete_allowed_parent', ['names' => $referencedBy->implode(', ')]),
            ]);
        }
    }
}
