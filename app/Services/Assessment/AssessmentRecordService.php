<?php

declare(strict_types=1);

namespace App\Services\Assessment;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\AssessmentForm;
use App\Models\AssessmentRecord;
use App\Models\AssessmentResponse;
use App\Models\Employee;
use App\Models\User;
use App\Services\Assessment\Execution\AssessmentAssignmentService;
use App\Services\Assessment\Execution\AssessmentResponseService;
use App\Services\Assessment\Execution\AssessmentReviewService;
use App\Services\Assessment\Execution\CompetencyAssessmentResultService;
use App\Services\DailyActivity\EmployeeWorkContextResolver;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Manual assessment assignment and the record-level actions of the
 * "Assessments & Summary" page. Execution itself (drafts, submission,
 * scoring, review, finalization, acknowledgement) lives in the
 * Execution services; this class delegates to them so there is one engine.
 */
class AssessmentRecordService
{
    public function __construct(
        private AssessmentTargetResolver $targets,
        private OrganizationScopeService $scope,
        private EmployeeWorkContextResolver $context,
        private WriteAuditLogAction $audit,
        private AssessmentAssignmentService $assignments,
        private AssessmentResponseService $responses,
        private AssessmentReviewService $reviews,
        private CompetencyAssessmentResultService $results,
    ) {}

    public function manages(User $user, string $organizationId): bool
    {
        return $user->can('assessment_forms.edit_draft') && $this->scope->canAccessOrganization($user, $organizationId);
    }

    public function visible(User $user, AssessmentRecord $record): bool
    {
        return $this->manages($user, $record->organization_id)
            || $user->employee?->id === $record->employee_id
            || $user->id === $record->reviewer_id
            || $record->responses()->where('evaluator_id', $user->id)->exists();
    }

    /**
     * Assign one employee outside a generated cycle run. The form version is
     * resolved by target rules on the period end, never chosen freely; peers
     * fill the peer scheme, self and direct manager are resolved.
     */
    public function assign(User $actor, array $data): AssessmentRecord
    {
        return DB::transaction(function () use ($actor, $data): AssessmentRecord {
            $employee = Employee::query()->lockForUpdate()->findOrFail($data['employee_id']);
            $form = AssessmentForm::query()->findOrFail($data['form_id']);
            abort_unless($actor->can('view', $form), 403);
            $date = Carbon::parse($data['period_end']);
            $resolved = $this->targets->resolveFor($employee, $date, $form->assessment_type_id);
            $assignment = $resolved['assignment'];
            abort_unless($assignment && $this->manages($actor, $assignment->organization_id), 403);
            $version = $resolved['version'];
            if (! $version || $version->form_id !== $form->id) {
                $this->invalid('form_id', 'No unique published form matches this employee on the period end date. Check target rules and conflicting forms.');
            }
            if (AssessmentRecord::query()->where('employee_id', $employee->id)->where('assessment_type_id', $form->assessment_type_id)
                ->whereDate('period_start', '<=', $data['period_end'])->whereDate('period_end', '>=', $data['period_start'])->exists()) {
                $this->invalid('employee_id', 'An assessment already covers this employee and period.');
            }
            $reviewer = User::query()->findOrFail($data['reviewer_id']);
            if (! $reviewer->isActive() || $reviewer->employee?->id === $employee->id || ! $this->scope->canAccessOrganization($reviewer, $assignment->organization_id)
                || ! $reviewer->can('assessments.finalize') || in_array($reviewer->id, $data['evaluator_ids'] ?? [])) {
                $this->invalid('reviewer_id', 'Select an active reviewer with the finalize permission in this organization, distinct from the employee and evaluators.');
            }
            $assignment->loadMissing(['organization', 'organizationUnit', 'position']);
            $record = AssessmentRecord::query()->create([
                'employee_id' => $employee->id, 'organization_id' => $assignment->organization_id, 'organization_unit_id' => $assignment->organization_unit_id,
                'assessment_type_id' => $form->assessment_type_id, 'form_version_id' => $version->id,
                'period_start' => $data['period_start'], 'period_end' => $data['period_end'],
                'created_by' => $actor->id, 'reviewer_id' => $reviewer->id, 'status' => 'pending_assignment',
                'employee_snapshot' => $this->assignments->snapshot($employee, $assignment),
            ]);
            $this->audit->execute(AuditEventType::AssessmentAssignmentCreated, $actor, $record, $record->organization_id, newValues: ['employee_id' => $employee->id, 'form_version_id' => $version->id, 'manual' => true], request: request());
            $this->assignments->fillResolvable($record, $version, $employee, $assignment, $actor);
            $peers = $version->evaluatorSchemes->first(fn ($s) => $s->evaluator_type->value === 'peer');
            if ($peers !== null) {
                if (count($data['evaluator_ids'] ?? []) !== $peers->required_count) {
                    $this->invalid('evaluator_ids', 'Select exactly '.$peers->required_count.' peer evaluator(s), as configured in the form.');
                }
                $this->assignments->assignEvaluators($actor, $record->fresh(), 'peer', $data['evaluator_ids'], null, false);
            }
            $this->assignments->refreshAssignmentStatus($record->fresh());
            $this->assignments->notify($record->responses()->get());

            return $record->fresh();
        });
    }

    /** Legacy one-shot submission: same engine, same checks, scores resolved on the server. */
    public function submit(User $actor, AssessmentRecord $record, array $answers): void
    {
        $response = $record->responses()->where('evaluator_id', $actor->id)->whereIn('status', AssessmentResponse::ACTIVE)->first();
        abort_unless($response, 403);
        $this->responses->submit($actor, $response, $answers, [], $response->lock_version);
    }

    public function transition(User $actor, AssessmentRecord $record, string $action, array $data = []): void
    {
        match ($action) {
            'review' => $this->reviews->finalize($actor, $record),
            'acknowledge' => $this->results->acknowledge($actor, $record, $data['comment'] ?? null),
            default => $this->markUnassessed($actor, $record, $data),
        };
    }

    private function markUnassessed(User $actor, AssessmentRecord $record, array $data): void
    {
        DB::transaction(function () use ($actor, $record, $data): void {
            $record = AssessmentRecord::query()->lockForUpdate()->findOrFail($record->id);
            abort_unless($this->manages($actor, $record->organization_id), 403);
            if (! in_array($record->status, ['assigned', 'pending_assignment'], true) || $record->responses()->where('status', 'submitted')->exists()) {
                $this->invalid('status', 'A submitted assessment cannot be marked unassessed.');
            }
            $record->update(['status' => 'unassessed', 'unassessed_reason' => $data['reason'], 'unassessed_note' => $data['note'] ?? null]);
            // Open evaluator work stops; nothing is deleted.
            $record->responses()->whereIn('status', ['not_started', 'in_progress', 'returned', 'conflict_declared'])->update(['status' => 'cancelled', 'cancelled_at' => now()]);
            $this->audit->execute(AuditEventType::AssessmentRecordChanged, $actor, $record, $record->organization_id, newValues: ['action' => 'unassessed', 'status' => $record->status], request: request());
        });
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
