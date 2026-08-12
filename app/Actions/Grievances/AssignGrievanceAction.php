<?php

declare(strict_types=1);

namespace App\Actions\Grievances;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\GrievanceStatus;
use App\Models\Grievance;
use App\Models\GrievanceAssignment;
use App\Models\GrievanceCommittee;
use App\Models\GrievanceSlaRule;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

readonly class AssignGrievanceAction
{
    public function __construct(private WriteAuditLogAction $audit) {}

    public function execute(Grievance $grievance, GrievanceCommittee $committee, User $actor, ?string $notes = null): GrievanceAssignment
    {
        return DB::transaction(function () use ($grievance, $committee, $actor, $notes): GrievanceAssignment {
            GrievanceAssignment::query()
                ->where('grievance_id', $grievance->id)
                ->where('is_current', true)
                ->update(['is_current' => false]);

            $workingDaysLimit = 3;
            $slaRule = GrievanceSlaRule::query()
                ->where('origin_level', $grievance->origin_level->value)
                ->where(function ($q) use ($grievance): void {
                    $q->where('organization_id', $grievance->organization_id)
                        ->orWhereNull('organization_id');
                })
                ->where('status', 'active')
                ->orderByDesc('organization_id')
                ->first();

            if ($slaRule) {
                $workingDaysLimit = $slaRule->working_days_limit;
            }

            $dueAt = $this->addWorkingDays(now(), $workingDaysLimit);

            $assignment = GrievanceAssignment::query()->create([
                'grievance_id' => $grievance->id,
                'committee_id' => $committee->id,
                'assigned_by_user_id' => $actor->id,
                'assignment_type' => 'committee',
                'notes' => $notes,
                'assigned_at' => now(),
                'due_at' => $dueAt,
                'is_current' => true,
            ]);

            $grievance->update([
                'status' => GrievanceStatus::UnderReview,
                'current_assigned_type' => GrievanceCommittee::class,
                'current_assigned_id' => $committee->id,
            ]);

            $this->audit->execute(
                eventType: AuditEventType::GrievanceAssigned,
                actor: $actor,
                auditable: $grievance,
                organizationId: $grievance->organization_id,
                newValues: ['status' => GrievanceStatus::UnderReview->value, 'committee_id' => $committee->id],
            );

            return $assignment;
        });
    }

    private function addWorkingDays(Carbon $start, int $days): Carbon
    {
        $date = $start->copy();
        $added = 0;
        while ($added < $days) {
            $date->addDay();
            if (! $date->isWeekend()) {
                $added++;
            }
        }

        return $date;
    }
}
