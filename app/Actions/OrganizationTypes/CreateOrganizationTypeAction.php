<?php

declare(strict_types=1);

namespace App\Actions\OrganizationTypes;

use App\Actions\Audit\WriteAuditLogAction;
use App\Actions\CodeRules\GenerateCodeAction;
use App\Enums\AuditEventType;
use App\Enums\CodeRuleEntityType;
use App\Models\OrganizationType;
use App\Models\User;
use Illuminate\Support\Facades\DB;

readonly class CreateOrganizationTypeAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLogAction,
        private GenerateCodeAction $generateCodeAction,
    ) {}

    public function execute(array $attributes, User $actor): OrganizationType
    {
        return DB::transaction(function () use ($attributes, $actor): OrganizationType {
            $attributes['prefix'] = filled($attributes['prefix'] ?? null)
                ? $attributes['prefix']
                : OrganizationType::prefixFromName($attributes['name_am'] ?? null);

            $attributes['category'] = filled($attributes['category'] ?? null)
                ? $attributes['category']
                : (OrganizationType::DEFAULT_CATEGORIES[trim((string) ($attributes['name_am'] ?? ''))] ?? null);

            $attributes['code'] = $this->generateCodeAction->execute(
                CodeRuleEntityType::OrganizationType,
                array_filter(['code_prefix' => $attributes['prefix']]),
                $actor,
                $attributes['code'] ?? null,
                'code',
            );

            $type = OrganizationType::query()->create($attributes);

            $this->writeAuditLogAction->execute(
                AuditEventType::OrganizationTypeCreated,
                $actor,
                $type,
                null,
                newValues: $type->toArray(),
            );

            return $type;
        });
    }
}
