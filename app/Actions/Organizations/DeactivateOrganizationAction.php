<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The lighter-weight safe alternative to deleting an organization: marks it
 * inactive without removing the record or its references.
 */
readonly class DeactivateOrganizationAction
{
    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    public function execute(Organization $organization, User $actor): Organization
    {
        return DB::transaction(function () use ($organization, $actor): Organization {
            $oldValues = $organization->only(['status']);

            $organization->update(['status' => OrganizationStatus::Inactive]);

            $this->writeAuditLogAction->execute(
                AuditEventType::OrganizationDeactivated,
                $actor,
                $organization,
                $organization->id,
                oldValues: $oldValues,
                newValues: ['status' => OrganizationStatus::Inactive->value],
            );

            return $organization->fresh();
        });
    }
}
