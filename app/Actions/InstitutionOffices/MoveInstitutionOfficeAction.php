<?php

declare(strict_types=1);

namespace App\Actions\InstitutionOffices;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\InstitutionOffice;
use App\Models\User;
use Illuminate\Http\Request;

readonly class MoveInstitutionOfficeAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLogAction,
    ) {}

    public function execute(
        InstitutionOffice $office,
        ?string $newParentOfficeId,
        User $actor,
        ?Request $request = null,
    ): InstitutionOffice {
        $oldValues = ['parent_office_id' => $office->parent_office_id];

        $office->update([
            'parent_office_id' => $newParentOfficeId,
            'updated_by' => $actor->getKey(),
        ]);

        $this->writeAuditLogAction->execute(
            AuditEventType::InstitutionOfficeMoved,
            $actor,
            $office,
            $office->institution_id,
            oldValues: $oldValues,
            newValues: ['parent_office_id' => $newParentOfficeId],
            request: $request,
        );

        return $office->fresh() ?? $office;
    }
}
