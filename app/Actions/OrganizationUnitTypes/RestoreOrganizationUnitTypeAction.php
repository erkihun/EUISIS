<?php

declare(strict_types=1);

namespace App\Actions\OrganizationUnitTypes;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\OrganizationUnitType;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

readonly class RestoreOrganizationUnitTypeAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLogAction,
    ) {}

    public function execute(OrganizationUnitType $type, User $actor, ?Request $request = null): OrganizationUnitType
    {
        return DB::transaction(function () use ($type, $actor, $request): OrganizationUnitType {
            $type->restore();
            $type->update([
                'is_active' => true,
                'updated_by' => $actor->getKey(),
                'deleted_by' => null,
                'deletion_reason' => null,
            ]);

            $this->writeAuditLogAction->execute(
                AuditEventType::RecordRestored,
                $actor,
                $type,
                null,
                oldValues: ['is_active' => false],
                newValues: ['is_active' => true],
                request: $request,
            );

            return $type->fresh() ?? $type;
        });
    }
}
