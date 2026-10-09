<?php

declare(strict_types=1);

namespace App\Actions\OrganizationUnits;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\OrganizationUnitStatus;
use App\Models\OrganizationUnit;
use App\Models\User;
use Illuminate\Http\Request;

readonly class RestoreOrganizationUnitAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLogAction,
    ) {}

    public function execute(OrganizationUnit $unit, User $actor, ?Request $request = null): OrganizationUnit
    {
        $unit->restore();

        $unit->update([
            'status'     => OrganizationUnitStatus::Active,
            'updated_by' => $actor->getKey(),
            'deleted_by' => null,
            'deletion_reason' => null,
        ]);

        $this->writeAuditLogAction->execute(
            AuditEventType::RecordRestored,
            $actor,
            $unit,
            $unit->organization_id,
            oldValues: ['status' => OrganizationUnitStatus::Archived->value],
            newValues: ['status' => OrganizationUnitStatus::Active->value],
            request: $request,
        );

        return $unit->fresh() ?? $unit;
    }
}
