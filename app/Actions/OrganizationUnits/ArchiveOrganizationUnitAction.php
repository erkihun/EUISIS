<?php

declare(strict_types=1);

namespace App\Actions\OrganizationUnits;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\OrganizationUnitStatus;
use App\Models\OrganizationUnit;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

readonly class ArchiveOrganizationUnitAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLogAction,
    ) {}

    public function execute(OrganizationUnit $unit, User $actor, ?string $reason = null, ?Request $request = null): OrganizationUnit
    {
        $activeCount = $unit->assignments()->where('is_current', true)->count();

        if ($activeCount > 0) {
            throw ValidationException::withMessages([
                'unit' => "Cannot archive: {$activeCount} employee(s) are currently assigned to this unit.",
            ]);
        }

        $unit->update([
            'status'     => OrganizationUnitStatus::Archived,
            'updated_by' => $actor->getKey(),
            'deleted_by' => $actor->getKey(),
            'deletion_reason' => $reason,
        ]);

        $unit->delete(); // soft delete

        $this->writeAuditLogAction->execute(
            AuditEventType::RecordDeleted,
            $actor,
            $unit,
            $unit->organization_id,
            oldValues: ['status' => OrganizationUnitStatus::Active->value],
            newValues: ['status' => OrganizationUnitStatus::Archived->value, 'deleted_by' => $actor->getKey(), 'deletion_reason' => $reason],
            reason: $reason,
            request: $request,
        );

        return $unit;
    }
}
