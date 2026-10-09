<?php

declare(strict_types=1);

namespace App\Actions\OrganizationTypes;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\OrganizationType;
use App\Models\User;
use Illuminate\Support\Facades\DB;

readonly class ArchiveOrganizationTypeAction
{
    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    public function execute(OrganizationType $type, User $actor): OrganizationType
    {
        return DB::transaction(function () use ($type, $actor): OrganizationType {
            $type->update(['is_active' => false]);

            $this->writeAuditLogAction->execute(
                AuditEventType::OrganizationTypeArchived,
                $actor,
                $type,
                null,
                oldValues: ['is_active' => true],
                newValues: ['is_active' => false],
            );

            return $type->fresh();
        });
    }
}
