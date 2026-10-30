<?php

declare(strict_types=1);

namespace App\Services\Grievances;

use App\Enums\AuditEventType;
use App\Enums\Grievance\GrievanceAppealStatus;
use App\Enums\Grievance\GrievanceDecisionStatus;
use App\Enums\Grievance\GrievanceMovementType;
use App\Enums\GrievanceStatus;
use App\Models\Grievance;
use App\Models\GrievanceAppeal;
use App\Models\GrievanceCaseStage;
use App\Models\GrievanceDecision;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Employee appeal (docs/grievance-management.md §6.5): the handler DECIDED
 * and the employee challenges the outcome — distinct from a timeout
 * escalation. One appeal per decision (unique appealed_decision_id), within
 * the appeal window when policy defines one, to the next configured level.
 */
final class GrievanceAppealService
{
    public function __construct(
        private readonly GrievanceCaseAccessService $access,
        private readonly GrievanceRoutingService $routing,
        private readonly GrievanceCaseService $cases,
        private readonly GrievanceEvidenceService $evidence,
        private readonly GrievanceSettings $settings,
        private readonly GrievanceAudit $audit,
    ) {}

    /** @return array{allowed: bool, reason: string|null, decision: GrievanceDecision|null, deadline: string|null} */
    public function eligibility(Grievance $grievance): array
    {
        $deny = fn (string $reason, ?GrievanceDecision $d = null) => ['allowed' => false, 'reason' => $reason, 'decision' => $d, 'deadline' => $grievance->appeal_deadline_at?->toIso8601String()];

        if (! $this->settings->appealEnabled()) {
            return $deny('grievances.errors.appeal_disabled');
        }
        if ($grievance->status !== GrievanceStatus::DecisionIssued) {
            return $deny('grievances.errors.appeal_not_available');
        }
        $stage = $grievance->current_stage_id ? GrievanceCaseStage::query()->find($grievance->current_stage_id) : null;
        $decision = $stage?->decisions()->where('status', GrievanceDecisionStatus::Issued->value)->orderByDesc('version_no')->first();
        if ($stage === null || $decision === null) {
            return $deny('grievances.errors.appeal_not_available');
        }
        if (GrievanceAppeal::query()->where('appealed_decision_id', $decision->getKey())->exists()) {
            return $deny('grievances.errors.appeal_exists', $decision);
        }
        if ($grievance->appeal_deadline_at !== null && $grievance->appeal_deadline_at->isPast()) {
            return $deny('grievances.errors.appeal_window_closed', $decision);
        }
        if ($this->routing->nextHandlers($stage, GrievanceMovementType::EmployeeAppeal, $grievance->category_id) === []) {
            return $deny('grievances.errors.no_appeal_route', $decision);
        }

        return ['allowed' => true, 'reason' => null, 'decision' => $decision, 'deadline' => $grievance->appeal_deadline_at?->toIso8601String()];
    }

    /** @param  list<UploadedFile>  $files */
    public function file(Grievance $grievance, User $actor, string $reason, array $files = []): GrievanceAppeal
    {
        $this->access->authorize($actor->can('grievances.submit') && $this->access->isComplainant($actor, $grievance));
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => __('grievances.errors.reason_required')]);
        }

        try {
            $appeal = DB::transaction(function () use ($grievance, $actor, $reason): GrievanceAppeal {
                $grievance = $this->cases->lock($grievance);
                $eligibility = $this->eligibility($grievance);
                if (! $eligibility['allowed']) {
                    throw ValidationException::withMessages(['reason' => __((string) $eligibility['reason'])]);
                }
                $decision = $eligibility['decision'];
                $stage = GrievanceCaseStage::query()->whereKey($decision->case_stage_id)->lockForUpdate()->firstOrFail();

                $appeal = GrievanceAppeal::query()->create([
                    'grievance_id' => $grievance->getKey(),
                    'appealed_decision_id' => $decision->getKey(),
                    'from_stage_id' => $stage->getKey(),
                    'reason' => $reason,
                    'status' => GrievanceAppealStatus::Submitted,
                    'filed_by' => $actor->getKey(),
                    'filed_by_employee_id' => $actor->employee_id,
                    'filed_at' => now(),
                    'deadline_at' => $grievance->appeal_deadline_at,
                ]);

                $next = $this->routing->nextHandlers($stage, GrievanceMovementType::EmployeeAppeal, $grievance->category_id)[0];
                $newStage = $this->routing->createStage($grievance, $next['type'], $next['id'], GrievanceMovementType::EmployeeAppeal, $next['route'], $stage, 'appeal:'.$appeal->getKey(), $actor);
                $grievance->forceFill(['appeal_deadline_at' => null])->save();
                $appeal->forceFill(['status' => GrievanceAppealStatus::Routed, 'to_stage_id' => $newStage->getKey(), 'routed_at' => now()])->save();

                $this->audit->record(AuditEventType::GrievanceAppealFiled, $actor, $appeal, ['decision_id' => $decision->getKey(), 'to_stage_id' => $newStage->getKey()]);

                return $appeal;
            });
        } catch (QueryException $exception) {
            if (str_contains(strtolower($exception->getMessage()), 'unique')) {
                throw ValidationException::withMessages(['reason' => __('grievances.errors.appeal_exists')]);
            }
            throw $exception;
        }

        foreach ($files as $file) {
            $this->evidence->upload($grievance->refresh(), $file, ['evidence_type' => 'document', 'title' => __('grievances.labels.appeal_attachment'), 'appeal_id' => $appeal->getKey()], $actor, true);
        }

        return $appeal;
    }
}
