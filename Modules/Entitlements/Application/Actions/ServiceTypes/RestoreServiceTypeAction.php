<?php

declare(strict_types=1);

namespace Modules\Entitlements\Application\Actions\ServiceTypes;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\User;
use Illuminate\Http\Request;
use Modules\Entitlements\Domain\Models\ServiceType;

class RestoreServiceTypeAction
{
    public function __construct(
        private readonly WriteAuditLogAction $writeAuditLogAction,
    ) {}

    public function execute(ServiceType $serviceType, ?User $actor = null, ?Request $request = null): ServiceType
    {
        $oldValues = $serviceType->toArray();

        $serviceType->restore();
        $serviceType->forceFill([
            'is_active' => true,
            'deleted_by' => null,
            'deletion_reason' => null,
        ])->save();

        $this->writeAuditLogAction->execute(
            AuditEventType::RecordRestored,
            $actor,
            $serviceType->fresh(),
            null,
            $oldValues,
            $serviceType->fresh()->toArray(),
            request: $request,
        );

        return $serviceType->fresh() ?? $serviceType;
    }
}
