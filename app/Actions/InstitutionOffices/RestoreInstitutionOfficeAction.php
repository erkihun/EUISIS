<?php

declare(strict_types=1);

namespace App\Actions\InstitutionOffices;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\InstitutionOffice;
use App\Models\User;
use Illuminate\Http\Request;

readonly class RestoreInstitutionOfficeAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLogAction,
    ) {}

    public function execute(InstitutionOffice $office, User $actor, ?Request $request = null): InstitutionOffice
    {
        $office->restore();

        $this->writeAuditLogAction->execute(
            AuditEventType::InstitutionOfficeRestored,
            $actor,
            $office,
            $office->institution_id,
            newValues: $office->toArray(),
            request: $request,
        );

        return $office;
    }
}
