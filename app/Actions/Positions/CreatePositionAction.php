<?php

declare(strict_types=1);

namespace App\Actions\Positions;

use App\Actions\Audit\WriteAuditLogAction;
use App\Actions\CodeRules\GenerateCodeAction;
use App\Enums\AuditEventType;
use App\Enums\CodeRuleEntityType;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\User;
use App\Services\CodeGeneration\PositionCodeContextResolver;

readonly class CreatePositionAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLogAction,
        private GenerateCodeAction $generateCodeAction,
        private PositionCodeContextResolver $positionCodeContextResolver,
    ) {}

    public function execute(array $attributes, User $actor): Position
    {
        $organizationId = $attributes['organization_id'] ?? null;
        $organizationUnitId = $attributes['organization_unit_id'] ?? null;

        // The form allows a nullable organization when a unit is selected —
        // fill it from the unit so the position always carries its organization.
        if (empty($organizationId) && ! empty($organizationUnitId)) {
            $organizationId = OrganizationUnit::query()->whereKey($organizationUnitId)->value('organization_id');
            $attributes['organization_id'] = $organizationId;
        }

        // Owner/host validation applies to generated codes; manual overrides
        // still pass through GenerateCodeAction's own override rules.
        $manualCode = $attributes['job_position_code'] ?? null;

        $codeContext = [
            'organization_id' => $organizationId,
            'organization_unit_id' => $organizationUnitId,
        ];

        if ($manualCode === null || trim((string) $manualCode) === '') {
            $resolved = $this->positionCodeContextResolver->validateForGeneration(
                $organizationId !== null ? (string) $organizationId : null,
                $organizationUnitId !== null ? (string) $organizationUnitId : null,
            );

            $codeContext['owner_organization_id'] = $resolved['owner_organization_id'];
            $codeContext['host_organization_id'] = $resolved['host_organization_id'];
        }

        $attributes['job_position_code'] = $this->generateCodeAction->execute(
            CodeRuleEntityType::Position,
            $codeContext,
            $actor,
            $manualCode,
            'job_position_code',
        );

        $position = Position::query()->create($attributes);

        $this->writeAuditLogAction->execute(
            AuditEventType::PositionCreated,
            $actor,
            $position,
            $position->organization_id,
            newValues: $position->toArray(),
        );

        return $position;
    }
}
