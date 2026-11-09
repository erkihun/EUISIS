<?php

declare(strict_types=1);

namespace App\Actions\OrganizationTypes;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\OrganizationType;
use App\Models\User;
use Illuminate\Support\Facades\DB;

readonly class RestoreOrganizationTypeAction
{
    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    public function execute(OrganizationType $type, User $actor): OrganizationType
    {
        return DB::transaction(function () use ($type, $actor): OrganizationType {
            $type->restore();
            $type->deleted_by = null;
            $type->save();

            $this->writeAuditLogAction->execute(
                AuditEventType::OrganizationTypeRestored,
                $actor,
                $type,
                null,
            );

            return $type->fresh();
        });
    }
}
