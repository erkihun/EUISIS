<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

readonly class ArchiveOrganizationAction
{
    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    public function execute(Organization $organization, User $actor): Organization
    {
        return DB::transaction(function () use ($organization, $actor): Organization {
            $oldStatus = $organization->status instanceof \BackedEnum
                ? $organization->status->value
                : (string) $organization->status;

            $organization->update(['status' => OrganizationStatus::Archived]);

            $this->writeAuditLogAction->execute(
                AuditEventType::OrganizationArchived,
                $actor,
                $organization,
                $organization->id,
                oldValues: ['status' => $oldStatus],
                newValues: ['status' => OrganizationStatus::Archived->value],
            );

            return $organization->fresh();
        });
    }
}
