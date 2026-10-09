<?php

declare(strict_types=1);

namespace App\Actions\OrganizationUnitTypes;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\OrganizationUnitType;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

readonly class ArchiveOrganizationUnitTypeAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLogAction,
    ) {}

    public function execute(OrganizationUnitType $type, User $actor, ?string $reason = null, ?Request $request = null): OrganizationUnitType
    {
        return DB::transaction(function () use ($type, $actor, $reason, $request): OrganizationUnitType {
            $type->update([
                'is_active' => false,
                'updated_by' => $actor->getKey(),
                'deleted_by' => $actor->getKey(),
                'deletion_reason' => $reason,
            ]);
            $type->delete(); // soft delete

            $this->writeAuditLogAction->execute(
                AuditEventType::RecordDeleted,
                $actor,
                $type,
                null,
                oldValues: ['is_active' => true],
                newValues: ['is_active' => false, 'deleted_by' => $actor->getKey(), 'deletion_reason' => $reason],
                reason: $reason,
                request: $request,
            );

            return $type;
        });
    }
}
