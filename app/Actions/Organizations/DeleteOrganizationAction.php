<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\Organization;
use App\Models\User;
use App\Services\Organizations\OrganizationDeletionGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

readonly class DeleteOrganizationAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLogAction,
        private OrganizationDeletionGuard $organizationDeletionGuard,
    ) {}

    /**
     * Soft-delete the organization, re-validating dependencies inside the
     * transaction (with a row lock) so a dependency created between the
     * policy check and this call cannot slip through.
     *
     * @throws ValidationException when the organization is still in use.
     */
    public function execute(Organization $organization, User $actor): void
    {
        DB::transaction(function () use ($organization, $actor): void {
            /** @var Organization $locked */
            $locked = Organization::query()
                ->whereKey($organization->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $this->organizationDeletionGuard->canBeDeletedSafely($locked)) {
                throw ValidationException::withMessages([
                    'organization' => __('organizations.cannot_delete_used'),
                ]);
            }

            $oldValues = $locked->toArray();

            $locked->forceFill([
                'deleted_by' => $actor->id,
            ])->save();

            $locked->delete();

            $this->writeAuditLogAction->execute(
                AuditEventType::OrganizationDeleted,
                $actor,
                $locked,
                $locked->id,
                oldValues: $oldValues,
                newValues: ['deleted_at' => now()->toDateTimeString(), 'deleted_by' => $actor->id],
            );
        });
    }
}
