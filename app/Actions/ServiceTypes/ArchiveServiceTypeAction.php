<?php

declare(strict_types=1);

namespace App\Actions\ServiceTypes;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\ServiceType;
use App\Models\User;
use Illuminate\Http\Request;

class ArchiveServiceTypeAction
{
    public function __construct(
        private readonly WriteAuditLogAction $writeAuditLogAction,
    ) {}

    public function execute(ServiceType $serviceType, ?User $actor = null, ?string $reason = null, ?Request $request = null): ServiceType
    {
        $oldValues = $serviceType->toArray();

        $serviceType->forceFill([
            'is_active' => false,
            'deleted_by' => $actor?->getKey(),
            'deletion_reason' => $reason,
        ])->save();

        $serviceType->delete();

        $this->writeAuditLogAction->execute(
            AuditEventType::RecordDeleted,
            $actor,
            $serviceType,
            null,
            $oldValues,
            ['deleted_at' => now()->toISOString(), 'deleted_by' => $actor?->getKey(), 'deletion_reason' => $reason],
            $reason,
            $request,
        );

        $this->writeAuditLogAction->execute(
            AuditEventType::ServiceTypeArchived,
            $actor,
            $serviceType,
            null,
            $oldValues,
            ['deleted_at' => now()->toISOString()],
            $reason,
            $request,
        );

        return $serviceType;
    }
}
