<?php

declare(strict_types=1);

namespace Modules\Entitlements\Application\Actions\Entitlements;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\Employee;
use App\Models\User;
use Modules\Entitlements\Domain\Enums\EntitlementStatus;
use Modules\Entitlements\Domain\Models\Entitlement;
use Modules\Entitlements\Domain\Models\ServiceProvider;
use Modules\Entitlements\Domain\Models\ServiceType;

readonly class GrantEntitlementAction
{
    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    public function execute(Employee $employee, ServiceType $serviceType, ?ServiceProvider $provider, User $actor, ?int $quotaLimit = null): Entitlement
    {
        $entitlement = Entitlement::query()->create([
            'employee_id' => $employee->id,
            'service_type_id' => $serviceType->id,
            'service_provider_id' => $provider?->id,
            'status' => EntitlementStatus::Active,
            'quota_limit' => $quotaLimit,
            'effective_from' => now()->toDateString(),
            'rule_snapshot' => ['granted_by' => $actor->email],
        ]);

        $this->writeAuditLogAction->execute(
            AuditEventType::EntitlementGranted,
            $actor,
            $entitlement,
            $employee->currentAssignment?->organization_id,
            newValues: $entitlement->toArray(),
        );

        return $entitlement;
    }
}
