<?php

declare(strict_types=1);

namespace App\Actions\Positions;

use App\Actions\Audit\WriteAuditLogAction;
use App\Actions\CodeRules\GenerateCodeAction;
use App\Enums\AuditEventType;
use App\Enums\CodeRuleEntityType;
use App\Enums\EstablishmentStatus;
use App\Models\PositionEstablishment;
use App\Models\User;

readonly class StorePositionEstablishmentAction
{
    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    public function execute(array $data, User $actor): PositionEstablishment
    {
        $establishment = PositionEstablishment::create([
            'establishment_number' => $this->generateNumber(),
            'organization_id' => $data['organization_id'],
            'organization_unit_id' => $data['organization_unit_id'] ?? null,
            'position_id' => $data['position_id'],
            'occupation_id' => $data['occupation_id'] ?? null,
            'approved_slots' => $data['approved_slots'],
            'effective_from' => $data['effective_from'],
            'effective_to' => $data['effective_to'] ?? null,
            'status' => EstablishmentStatus::Draft->value,
            'approval_reference' => $data['approval_reference'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        $this->writeAuditLogAction->execute(
            AuditEventType::PositionEstablishmentCreated,
            $actor,
            $establishment,
            $establishment->organization_id,
            newValues: $establishment->toArray(),
        );

        return $establishment;
    }

    /** From the position-establishment code rule (EST-YYYYMM-NNNNN by default). */
    private function generateNumber(): string
    {
        return app(GenerateCodeAction::class)->execute(CodeRuleEntityType::PositionEstablishment, [], null, null, 'establishment_number');
    }
}
