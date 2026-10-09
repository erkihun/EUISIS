<?php

declare(strict_types=1);

namespace App\Actions\InstitutionOffices;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\InstitutionOffice;
use App\Models\User;
use Illuminate\Http\Request;

readonly class DeleteInstitutionOfficeAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLogAction,
    ) {}

    public function execute(InstitutionOffice $office, User $actor, ?Request $request = null): void
    {
        $this->writeAuditLogAction->execute(
            AuditEventType::InstitutionOfficeDeleted,
            $actor,
            $office,
            $office->institution_id,
            oldValues: $office->toArray(),
            request: $request,
        );

        $office->delete();
    }
}
