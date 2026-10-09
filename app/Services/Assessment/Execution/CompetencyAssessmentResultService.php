<?php

declare(strict_types=1);

namespace App\Services\Assessment\Execution;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\AssessmentRecord;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The controlled outlet for finalized competency-assessment results
 * (docs/assessment-scoring-workflow.md#epms): EPMS and reports read results
 * here and never write employee performance scores from a controller. Only
 * finalized (reviewed or acknowledged) records are ever returned. Also the
 * employee's own result view and acknowledgement.
 */
class CompetencyAssessmentResultService
{
    public function __construct(private readonly WriteAuditLogAction $audit) {}

    /**
     * Finalized results of one employee for EPMS consumption.
     *
     * @return Collection<int, array{record_id: string, cycle_id: ?string, assessment_type_id: string, form_version_id: string, normalized_score: ?string, contribution: ?string, band_code: ?string, finalized_at: ?string}>
     */
    public function finalizedFor(string $employeeId, ?string $cycleId = null): Collection
    {
        return AssessmentRecord::query()->where('employee_id', $employeeId)->whereIn('status', ['reviewed', 'acknowledged'])
            ->when($cycleId, fn ($q, $v) => $q->where('assessment_cycle_id', $v))->orderByDesc('finalized_at')->get()
            ->map(fn (AssessmentRecord $r): array => [
                'record_id' => $r->id, 'cycle_id' => $r->assessment_cycle_id, 'assessment_type_id' => $r->assessment_type_id, 'form_version_id' => $r->form_version_id,
                'normalized_score' => $r->percentage === null ? null : (string) $r->percentage, 'contribution' => $r->contribution === null ? null : (string) $r->contribution,
                'band_code' => $r->band_code, 'finalized_at' => $r->finalized_at?->toIso8601String(),
            ]);
    }

    /**
     * What the employee may see of their own record: status and, once final,
     * the result, band and evaluator-type components. Never evaluator names
     * of anonymous components, internal review notes, other evaluators'
     * comments or drafts.
     *
     * @return array<string, mixed>
     */
    public function employeeView(AssessmentRecord $record): array
    {
        $final = $record->isFinalized();

        return [
            'id' => $record->id, 'status' => $record->status, 'period_start' => $record->period_start?->toDateString(), 'period_end' => $record->period_end?->toDateString(),
            'cycle' => $record->cycle?->only(['id', 'code', 'name_en', 'name_am']), 'type' => $record->version?->form?->type?->only(['code', 'name_en', 'name_am']),
            'form' => $record->version?->only(['name_en', 'name_am', 'version_no']),
            'final' => $final,
            'percentage' => $final && $record->percentage !== null ? (string) $record->percentage : null,
            'band' => $final && $record->band_code ? ['code' => $record->band_code, 'label_en' => $record->band_label_en, 'label_am' => $record->band_label_am] : null,
            'components' => $final ? collect($record->score_breakdown['components'] ?? [])->map(fn (array $c): array => [
                'type' => $c['type'], 'submitted' => $c['submitted'], 'percentage' => $c['percentage'], 'weight' => $c['weight'],
            ])->values()->all() : [],
            'finalized_at' => $record->finalized_at?->toIso8601String(),
            'acknowledgement_required' => (bool) $record->version?->acknowledgement_required,
            'acknowledged_at' => $record->acknowledged_at?->toIso8601String(), 'acknowledgement_comment' => $record->acknowledgement_comment,
            'can_acknowledge' => $record->status === 'reviewed' && (bool) $record->version?->acknowledgement_required,
        ];
    }

    /** Acknowledgement records receipt; it does not mean the employee agrees. */
    public function acknowledge(User $user, AssessmentRecord $record, ?string $comment): void
    {
        abort_unless($user->employee_id !== null && $user->employee_id === $record->employee_id && $user->can('assessments.view_own_result'), 403);
        if ($record->status !== 'reviewed' || ! $record->version?->acknowledgement_required) {
            throw ValidationException::withMessages(['record' => __('assessments.execution.cannot_acknowledge')]);
        }
        $record->update(['status' => 'acknowledged', 'acknowledged_by' => $user->id, 'acknowledged_at' => now(), 'acknowledgement_comment' => $comment ? trim($comment) : null]);
        $this->audit->execute(AuditEventType::AssessmentAcknowledged, $user, $record, $record->organization_id, newValues: ['record_id' => $record->id], request: request());
    }
}
