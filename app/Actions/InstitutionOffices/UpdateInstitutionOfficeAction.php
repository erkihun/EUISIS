<?php

declare(strict_types=1);

namespace App\Actions\InstitutionOffices;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\InstitutionOffice;
use App\Models\User;
use Illuminate\Http\Request;

readonly class UpdateInstitutionOfficeAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLogAction,
    ) {}

    public function execute(InstitutionOffice $office, array $attributes, User $actor, ?Request $request = null): InstitutionOffice
    {
        $oldValues = $office->toArray();

        $attributes['updated_by'] = $actor->getKey();

        $office->update($attributes);

        $this->writeAuditLogAction->execute(
            AuditEventType::InstitutionOfficeUpdated,
            $actor,
            $office,
            $office->institution_id,
            oldValues: $oldValues,
            newValues: $office->fresh()?->toArray(),
            request: $request,
        );

        return $office->fresh() ?? $office;
    }
}
