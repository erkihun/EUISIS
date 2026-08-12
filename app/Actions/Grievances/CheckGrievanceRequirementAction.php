<?php

declare(strict_types=1);

namespace App\Actions\Grievances;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\GrievanceStatus;
use App\Models\Grievance;
use App\Models\User;

readonly class CheckGrievanceRequirementAction
{
    public function __construct(private WriteAuditLogAction $audit) {}

    public function execute(Grievance $grievance, User $actor, bool $fulfilled, ?string $notes = null): Grievance
    {
        $newStatus = $fulfilled
            ? GrievanceStatus::RequirementFulfilled
            : GrievanceStatus::RequirementIncomplete;

        $grievance->update([
            'status' => $newStatus,
            'requirement_fulfilled' => $fulfilled,
            'requirement_notes' => $notes,
            'requirement_checked_at' => now(),
        ]);

        $this->audit->execute(
            eventType: AuditEventType::GrievanceRequirementChecked,
            actor: $actor,
            auditable: $grievance,
            organizationId: $grievance->organization_id,
            newValues: [
                'status' => $newStatus->value,
                'requirement_fulfilled' => $fulfilled,
            ],
        );

        return $grievance->refresh();
    }
}
