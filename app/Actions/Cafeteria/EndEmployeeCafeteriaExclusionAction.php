<?php

declare(strict_types=1);

namespace App\Actions\Cafeteria;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\CafeteriaExclusionStatus;
use App\Models\EmployeeCafeteriaExclusion;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

readonly class EndEmployeeCafeteriaExclusionAction
{
    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    public function execute(EmployeeCafeteriaExclusion $exclusion, User $actor, ?string $returnToWorkOn, ?Request $request = null): void
    {
        $exclusion->update([
            'status'            => CafeteriaExclusionStatus::Ended->value,
            'ended_by'          => $actor->id,
            'ended_at'          => Carbon::now(),
            'return_to_work_on' => $returnToWorkOn ?? $exclusion->return_to_work_on,
            'updated_by'        => $actor->id,
        ]);

        $this->writeAuditLogAction->execute(
            AuditEventType::EmployeeCafeteriaExclusionEnded,
            $actor,
            $exclusion,
            $exclusion->employee?->organization_id,
            newValues: ['status' => 'ended', 'return_to_work_on' => $returnToWorkOn],
            request: $request,
        );
    }
}
