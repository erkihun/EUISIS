<?php

declare(strict_types=1);

namespace App\Actions\ServiceTypes;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\ServiceType;
use App\Models\User;

class UpdateServiceTypeAction
{
    public function __construct(
        private readonly WriteAuditLogAction $writeAuditLogAction,
    ) {}

    public function execute(ServiceType $serviceType, array $attributes, ?User $actor = null): ServiceType
    {
        $oldValues = $serviceType->only(['code', 'name_en', 'name_am', 'description', 'is_active']);

        $serviceType->fill($attributes)->save();

        $this->writeAuditLogAction->execute(
            AuditEventType::ServiceTypeUpdated,
            $actor,
            $serviceType,
            null,
            $oldValues,
            $serviceType->only(['code', 'name_en', 'name_am', 'description', 'is_active']),
        );

        return $serviceType;
    }
}
