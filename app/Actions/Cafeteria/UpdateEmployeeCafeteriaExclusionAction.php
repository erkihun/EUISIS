<?php

declare(strict_types=1);

namespace App\Actions\Cafeteria;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\EmployeeCafeteriaExclusion;
use App\Models\User;
use Illuminate\Http\Request;

readonly class UpdateEmployeeCafeteriaExclusionAction
{
    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    public function execute(EmployeeCafeteriaExclusion $exclusion, array $attributes, User $actor, ?Request $request = null): void
    {
        $old = $exclusion->only(['exclusion_type', 'starts_on', 'ends_on', 'return_to_work_on', 'is_open_ended']);

        $exclusion->update([
            'exclusion_type'    => $attributes['exclusion_type'] ?? $exclusion->exclusion_type,
            'starts_on'         => $attributes['starts_on'] ?? $exclusion->starts_on,
            'ends_on'           => $attributes['ends_on'] ?? null,
            'return_to_work_on' => $attributes['return_to_work_on'] ?? null,
            'is_open_ended'     => (bool) ($attributes['is_open_ended'] ?? $exclusion->is_open_ended),
            'reason_en'         => $attributes['reason_en'] ?? $exclusion->reason_en,
            'reason_am'         => $attributes['reason_am'] ?? $exclusion->reason_am,
            'updated_by'        => $actor->id,
        ]);

        $this->writeAuditLogAction->execute(
            AuditEventType::EmployeeCafeteriaExclusionUpdated,
            $actor,
            $exclusion,
            $exclusion->employee?->organization_id,
            oldValues: $old,
            newValues: $exclusion->only(['exclusion_type', 'starts_on', 'ends_on', 'return_to_work_on']),
            request: $request,
        );
    }
}
