<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\GrievanceStatus;
use App\Models\AdministrativeTribunalCase;
use App\Models\Grievance;
use App\Models\GrievanceAssignment;
use App\Models\GrievanceEscalation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EscalateOverdueGrievancesJob implements ShouldQueue
{
    use Queueable;

    public function handle(WriteAuditLogAction $audit): void
    {
        $overdueAssignments = GrievanceAssignment::query()
            ->with('grievance')
            ->where('is_current', true)
            ->where('due_at', '<', now())
            ->whereHas('grievance', function ($q): void {
                $q->whereIn('status', [
                    GrievanceStatus::Submitted->value,
                    GrievanceStatus::UnderReview->value,
                    GrievanceStatus::RequirementFulfilled->value,
                    GrievanceStatus::InProgress->value,
                ]);
            })
            ->get();

        foreach ($overdueAssignments as $assignment) {
            $grievance = $assignment->grievance;

            DB::transaction(function () use ($grievance, $assignment, $audit): void {
                $escalationCount = $grievance->escalations()->count();

                if ($escalationCount >= 1) {
                    $this->referToTribunal($grievance, $audit);
                } else {
                    $this->escalateToNextLevel($grievance, $assignment, $audit);
                }
            });
        }
    }

    private function escalateToNextLevel(Grievance $grievance, GrievanceAssignment $assignment, WriteAuditLogAction $audit): void
    {
        GrievanceEscalation::query()->create([
            'grievance_id' => $grievance->id,
            'from_level' => 'committee',
            'to_level' => 'committee',
            'reason' => 'sla_breach',
            'escalated_at' => now(),
        ]);

        $grievance->update(['status' => GrievanceStatus::Escalated]);

        $assignment->update(['due_at' => now()->addDays(3)]);

        $audit->execute(
            eventType: AuditEventType::GrievanceEscalated,
            actor: null,
            auditable: $grievance,
            organizationId: $grievance->organization_id,
            newValues: ['status' => GrievanceStatus::Escalated->value, 'reason' => 'sla_breach'],
        );
    }

    private function referToTribunal(Grievance $grievance, WriteAuditLogAction $audit): void
    {
        GrievanceEscalation::query()->create([
            'grievance_id' => $grievance->id,
            'from_level' => 'committee',
            'to_level' => 'administrative_tribunal',
            'reason' => 'sla_breach',
            'escalated_at' => now(),
        ]);

        $caseNumber = 'TRB-'.now()->format('Y').'-'.strtoupper(Str::random(6));

        AdministrativeTribunalCase::query()->firstOrCreate(
            ['grievance_id' => $grievance->id],
            [
                'case_number' => $caseNumber,
                'status' => 'open',
            ],
        );

        $grievance->update(['status' => GrievanceStatus::TribunalReferred]);

        $audit->execute(
            eventType: AuditEventType::GrievanceTribunalReferred,
            actor: null,
            auditable: $grievance,
            organizationId: $grievance->organization_id,
            newValues: ['status' => GrievanceStatus::TribunalReferred->value, 'case_number' => $caseNumber],
        );
    }
}
