<?php

declare(strict_types=1);

namespace App\Actions\Cafeteria;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\CafeteriaExclusionStatus;
use App\Models\Employee;
use App\Models\EmployeeCafeteriaExclusion;
use App\Models\User;
use Illuminate\Http\Request;

readonly class CreateEmployeeCafeteriaExclusionAction
{
    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    public function execute(array $attributes, User $actor, ?Request $request = null): EmployeeCafeteriaExclusion
    {
        $exclusion = EmployeeCafeteriaExclusion::query()->create([
            'employee_id'       => $attributes['employee_id'],
            'exclusion_type'    => $attributes['exclusion_type'],
            'starts_on'         => $attributes['starts_on'],
            'ends_on'           => $attributes['ends_on'] ?? null,
            'return_to_work_on' => $attributes['return_to_work_on'] ?? null,
            'is_open_ended'     => (bool) ($attributes['is_open_ended'] ?? false),
            'reason_en'         => $attributes['reason_en'] ?? null,
            'reason_am'         => $attributes['reason_am'] ?? null,
            'status'            => CafeteriaExclusionStatus::Active->value,
            'created_by'        => $actor->id,
            'updated_by'        => $actor->id,
        ]);

        $employee = Employee::query()->find($attributes['employee_id']);

        $this->writeAuditLogAction->execute(
            AuditEventType::EmployeeCafeteriaExclusionCreated,
            $actor,
            $exclusion,
            $employee?->organization_id,
            newValues: [
                'employee_id'    => $exclusion->employee_id,
                'exclusion_type' => $exclusion->exclusion_type,
                'starts_on'      => $exclusion->starts_on->toDateString(),
                'ends_on'        => $exclusion->ends_on?->toDateString(),
            ],
            request: $request,
        );

        return $exclusion;
    }
}
