<?php

declare(strict_types=1);

namespace App\Actions\ServiceTypes;

use App\Actions\Audit\WriteAuditLogAction;
use App\Actions\CodeRules\GenerateCodeAction;
use App\Enums\AuditEventType;
use App\Enums\CodeRuleEntityType;
use App\Models\ServiceType;
use App\Models\User;

class CreateServiceTypeAction
{
    public function __construct(
        private readonly WriteAuditLogAction $writeAuditLogAction,
        private readonly GenerateCodeAction $generateCodeAction,
    ) {}

    public function execute(array $attributes, ?User $actor = null): ServiceType
    {
        $attributes['code'] = $this->generateCodeAction->execute(
            CodeRuleEntityType::ServiceType,
            [],
            $actor,
            $attributes['code'] ?? null,
            'code',
        );

        $serviceType = ServiceType::query()->create($attributes);

        $this->writeAuditLogAction->execute(
            AuditEventType::ServiceTypeCreated,
            $actor,
            $serviceType,
            null,
            null,
            $serviceType->only(['code', 'name_en', 'name_am', 'is_active']),
        );

        return $serviceType;
    }
}
