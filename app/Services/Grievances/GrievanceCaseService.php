<?php

declare(strict_types=1);

namespace App\Services\Grievances;

use App\Actions\CodeRules\GenerateCodeAction;
use App\Enums\AuditEventType;
use App\Enums\CodeRuleEntityType;
use App\Enums\Grievance\GrievanceCaseOfficerRole;
use App\Enums\Grievance\GrievanceConfidentiality;
use App\Enums\Grievance\GrievanceCorrectiveActionStatus;
use App\Enums\Grievance\GrievanceHandlerType;
use App\Enums\Grievance\GrievanceMovementType;
use App\Enums\Grievance\GrievancePriority;
use App\Enums\Grievance\GrievanceReasonCodeType;
use App\Enums\Grievance\GrievanceRecordState;
use App\Enums\Grievance\GrievanceReferralStatus;
use App\Enums\Grievance\GrievanceSlaStartPoint;
use App\Enums\Grievance\GrievanceStageStatus;
use App\Enums\Grievance\GrievanceTaskStatus;
use App\Enums\Grievance\GrievanceTaskType;
use App\Enums\GrievanceOriginLevel;
use App\Enums\GrievanceStatus;
use App\Models\Grievance;
use App\Models\GrievanceAmendment;
use App\Models\GrievanceCaseOfficer;
use App\Models\GrievanceCaseStage;
use App\Models\GrievanceCategory;
use App\Models\GrievanceCorrectiveAction;
use App\Models\GrievanceDisciplinaryReferral;
use App\Models\GrievanceNote;
use App\Models\GrievanceReasonCode;
use App\Models\GrievanceTask;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Case lifecycle (docs/grievance-management.md §4): draft → submission →
 * intake → stages → decision → closure → archive, plus withdrawal, reopen
 * and legal hold. Every change runs in a transaction on a locked case row,
 * is audited, and lands on the timeline.
 *
 * Routing is always resolved server-side: the complainant never chooses a
 * handler, committee, organization or approver.
 */
final class GrievanceCaseService
{
    /** Fields a complainant may set; everything else is derived. */
    public const COMPLAINANT_FIELDS = ['subject', 'description', 'category_id', 'incident_date', 'respondent_description'];

    public function __construct(
        private readonly GrievanceCaseAccessService $access,
        private readonly GrievanceRoutingService $routing,
        private readonly GrievanceSlaService $sla,
        private readonly GrievanceHandlerRegistry $handlers,
        private readonly GrievanceSettings $settings,
        private readonly GrievanceAudit $audit,
        private readonly GrievanceTimeline $timeline,
        private readonly GrievanceNotifier $notifier,
        private readonly GenerateCodeAction $codes,
    ) {}

    // ── Drafts ───────────────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $data  complainant fields only */
    public function createDraft(User $actor, array $data): Grievance
    {
        $this->access->authorize($actor->can('grievances.create'));
        if (! $this->settings->enabled()) {
            throw ValidationException::withMessages(['grievance' => __('grievances.errors.module_disabled')]);
        }

        $employee = $this->access->employeeOf($actor);
        $placement = $this->handlers->placementOf($employee);
        if ($employee === null || $placement['organization_id'] === null) {
            throw ValidationException::withMessages(['grievance' => __('grievances.errors.no_employee_record')]);
        }

        $category = $this->activeCategory($data['category_id'] ?? null);

        return DB::transaction(function () use ($actor, $data, $employee, $placement, $category): Grievance {
            $grievance = Grievance::query()->create([
                // Placeholder until first submission assigns the official,
                // never-changing case number (reference_number is NOT NULL).
                'reference_number' => 'DRAFT-'.strtoupper((string) Str::ulid()),
                'submitted_by_user_id' => $actor->getKey(),
                'employee_id' => $employee->getKey(),
                'employee_assignment_id' => $placement['assignment_id'],
                'organization_id' => $placement['organization_id'],
                'organization_unit_id' => $placement['unit_ids'][0] ?? null,
                'origin_level' => $placement['unit_ids'] !== [] ? GrievanceOriginLevel::OrganizationUnit : GrievanceOriginLevel::Organization,
                'category_id' => $category->getKey(),
                'subject' => $data['subject'],
                'description' => $data['description'],
                'incident_date' => $data['incident_date'] ?? null,
                'respondent_description' => $data['respondent_description'] ?? null,
                'priority' => GrievancePriority::tryFrom((string) $category->default_priority) ?? GrievancePriority::Normal,
                'confidentiality_level' => GrievanceConfidentiality::tryFrom((string) $category->default_confidentiality) ?? GrievanceConfidentiality::NormalConfidential,
                'status' => GrievanceStatus::Draft,
                'record_state' => GrievanceRecordState::Active,
            ]);
            $this->audit->record(AuditEventType::GrievanceDraftCreated, $actor, $grievance, ['category_id' => $category->getKey()]);

            return $grievance;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function updateDraft(Grievance $grievance, User $actor, array $data): Grievance
    {
        $this->access->authorize($actor->can('grievances.update_draft') && $this->access->isComplainant($actor, $grievance));

        return DB::transaction(function () use ($grievance, $actor, $data): Grievance {
            $grievance = $this->lock($grievance);
            if (! $grievance->status->isEditableByComplainant()) {
                throw ValidationException::withMessages(['grievance' => __('grievances.errors.not_editable')]);
            }
            if (isset($data['category_id'])) {
                $this->activeCategory($data['category_id']);
            }

            $changes = array_intersect_key($data, array_flip(self::COMPLAINANT_FIELDS));
            $old = [];
            foreach ($changes as $field => $value) {
                $current = $grievance->getAttribute($field);
                $current = $current instanceof \DateTimeInterface ? $current->format('Y-m-d') : $current;
                if ((string) $current !== (string) $value) {
                    $old[$field] = $current;
                }
            }
            $grievance->fill($changes)->save();

            // A returned case is already on the record: keep what changed.
            if ($grievance->status !== GrievanceStatus::Draft && $old !== []) {
                GrievanceAmendment::query()->create([
                    'grievance_id' => $grievance->getKey(),
                    'changes' => collect($old)->map(fn ($value, $field) => ['from' => $value, 'to' => $changes[$field] ?? null])->all(),
                    'reason' => $data['amendment_reason'] ?? null,
                    'amended_by' => $actor->getKey(),
                    'amended_at' => now(),
                ]);
                $this->audit->record(AuditEventType::GrievanceAmended, $actor, $grievance, ['fields' => array_keys($old)]);
            } else {
                $this->audit->record(AuditEventType::GrievanceDraftUpdated, $actor, $grievance, ['fields' => array_keys($old)]);
            }

            return $grievance;
        });
    }

    /** Only a never-submitted draft is deleted; anything submitted is retained. */
    public function deleteDraft(Grievance $grievance, User $actor): void
    {
        $this->access->authorize($actor->can('grievances.update_draft') && $this->access->isComplainant($actor, $grievance));

        DB::transaction(function () use ($grievance, $actor): void {
            $grievance = $this->lock($grievance);
            if ($grievance->status !== GrievanceStatus::Draft || $grievance->submitted_at !== null || $grievance->stages()->exists()) {
                throw ValidationException::withMessages(['grievance' => __('grievances.errors.not_deletable')]);
            }
            foreach ($grievance->evidence as $evidence) {
                Storage::disk($evidence->disk)->delete($evidence->path);
            }
            $this->audit->record(AuditEventType::GrievanceDraftDeleted, $actor, $grievance, ['id' => $grievance->getKey()]);
            $grievance->delete();
        });
    }

    // ── Submission and intake ────────────────────────────────────────────────

    public function submit(Grievance $grievance, User $actor): Grievance
    {
        $this->access->authorize($actor->can('grievances.submit') && $this->access->isComplainant($actor, $grievance));

        return DB::transaction(function () use ($grievance, $actor): Grievance {
            $grievance = $this->lock($grievance);
            if (! $grievance->status->isEditableByComplainant()) {
                throw ValidationException::withMessages(['grievance' => __('grievances.errors.already_submitted')]);
            }
            $resubmission = $grievance->submitted_at !== null;

            if (str_starts_with((string) $grievance->reference_number, 'DRAFT-')) {
                $grievance->reference_number = $this->codes->execute(CodeRuleEntityType::GrievanceCase, ['organization_id' => $grievance->organization_id], $actor, null, 'reference_number', $grievance->getKey());
            }
            $grievance->forceFill([
                'status' => GrievanceStatus::Submitted,
                'submitted_at' => $grievance->submitted_at ?? now(),
            ])->save();

            $this->audit->record(AuditEventType::GrievanceSubmitted, $actor, $grievance, ['reference_number' => $grievance->reference_number, 'resubmission' => $resubmission]);
            $this->timeline->record($grievance, $resubmission ? 'resubmitted' : 'submitted', $actor);
            $this->notifier->toComplainant($grievance, 'submitted');

            if (! $this->settings->intakeReviewEnabled()) {
                $this->acceptAndRoute($grievance, null, null);
            } else {
                $this->notifyIntakeOfficers($grievance);
            }

            return $grievance->refresh();
        });
    }

    public function intakeAccept(Grievance $grievance, User $actor, ?string $notes): Grievance
    {
        $this->access->authorize($this->access->canIntake($actor, $grievance));

        return DB::transaction(function () use ($grievance, $actor, $notes): Grievance {
            $grievance = $this->lock($grievance);
            if (! in_array($grievance->status, GrievanceCaseAccessService::INTAKE_STATUSES, true)) {
                throw ValidationException::withMessages(['grievance' => __('grievances.errors.stale')]);
            }
            $grievance->forceFill(['intake_notes' => $notes])->save();
            $this->acceptAndRoute($grievance, $actor, $notes);
            $this->audit->record(AuditEventType::GrievanceIntakeAccepted, $actor, $grievance, ['stage_id' => $grievance->current_stage_id]);

            return $grievance->refresh();
        });
    }

    public function intakeReturn(Grievance $grievance, User $actor, string $reasonCode, ?string $notes): Grievance
    {
        $this->access->authorize($this->access->canIntake($actor, $grievance));
        $this->assertReasonCode(GrievanceReasonCodeType::IntakeReturn, $reasonCode);

        return DB::transaction(function () use ($grievance, $actor, $reasonCode, $notes): Grievance {
            $grievance = $this->lock($grievance);
            if (! in_array($grievance->status, GrievanceCaseAccessService::INTAKE_STATUSES, true)) {
                throw ValidationException::withMessages(['grievance' => __('grievances.errors.stale')]);
            }
            $grievance->forceFill(['status' => GrievanceStatus::ReturnedForCorrection, 'intake_reason_code' => $reasonCode, 'intake_notes' => $notes])->save();
            $this->audit->record(AuditEventType::GrievanceIntakeReturned, $actor, $grievance, ['reason_code' => $reasonCode]);
            $this->timeline->record($grievance, 'returned_for_correction', $actor, ['reason_code' => $reasonCode]);
            $this->notifier->toComplainant($grievance, 'returned_for_correction');

            return $grievance;
        });
    }

    public function intakeReject(Grievance $grievance, User $actor, string $reasonCode, ?string $notes): Grievance
    {
        $this->access->authorize($this->access->canIntake($actor, $grievance));
        if (! $this->settings->allowRejectionAtIntake()) {
            throw ValidationException::withMessages(['reason_code' => __('grievances.errors.intake_rejection_disabled')]);
        }
        $this->assertReasonCode(GrievanceReasonCodeType::IntakeRejection, $reasonCode);

        return DB::transaction(function () use ($grievance, $actor, $reasonCode, $notes): Grievance {
            $grievance = $this->lock($grievance);
            if (! in_array($grievance->status, GrievanceCaseAccessService::INTAKE_STATUSES, true)) {
                throw ValidationException::withMessages(['grievance' => __('grievances.errors.stale')]);
            }
            $grievance->forceFill([
                'status' => GrievanceStatus::RejectedAtIntake,
                'intake_reason_code' => $reasonCode,
                'intake_notes' => $notes,
                'closed_at' => now(),
                'closed_by' => $actor->getKey(),
                'closure_reason_code' => $reasonCode,
                'record_state' => GrievanceRecordState::Closed,
                'retention_until' => $this->retentionUntil(),
            ])->save();
            $this->audit->record(AuditEventType::GrievanceIntakeRejected, $actor, $grievance, ['reason_code' => $reasonCode]);
            $this->timeline->record($grievance, 'rejected_at_intake', $actor, ['reason_code' => $reasonCode]);
            $this->notifier->toComplainant($grievance, 'rejected_at_intake');

            return $grievance;
        });
    }

    /** Must run inside a transaction with the grievance locked. */
    private function acceptAndRoute(Grievance $grievance, ?User $actor, ?string $notes): void
    {
        $initial = $this->routing->resolveInitialHandler($grievance);
        if ($initial === null) {
            // Nothing routable: keep it visible at intake instead of losing it.
            $grievance->forceFill(['status' => GrievanceStatus::IntakeReview])->save();
            $this->timeline->record($grievance, 'routing_unresolved', $actor);
            if ($actor !== null) {
                throw ValidationException::withMessages(['route' => __('grievances.errors.no_initial_route')]);
            }
            $this->notifyIntakeOfficers($grievance);

            return;
        }

        $grievance->forceFill(['accepted_at' => now()])->save();
        $this->routing->createStage($grievance, $initial['type'], $initial['id'], GrievanceMovementType::InitialAssignment, $initial['route'], null, $notes, $actor);
        $this->notifier->toComplainant($grievance, 'accepted');
    }

    private function notifyIntakeOfficers(Grievance $grievance): void
    {
        $officers = User::permission('grievances.intake_review')->where('status', 'active')->get()
            ->filter(fn (User $u) => ! $grievance->isOwnedBy($u) && $this->access->isIntakeOfficer($u, $grievance));
        $this->notifier->toUsers($officers, 'intake_pending', $grievance);
    }

    // ── Stage work ───────────────────────────────────────────────────────────

    /** Handler acknowledges receipt (starts an on-receipt SLA clock). */
    public function receive(Grievance $grievance, User $actor): GrievanceCaseStage
    {
        $this->access->authorize($this->access->canHandle($actor, $grievance, 'grievances.view_assigned') || $this->access->canHandle($actor, $grievance, 'grievances.assign'));

        return DB::transaction(function () use ($grievance, $actor): GrievanceCaseStage {
            $grievance = $this->lock($grievance);
            $stage = $this->lockedCurrentStage($grievance);
            if ($stage->status === GrievanceStageStatus::Pending) {
                $stage->forceFill(['status' => GrievanceStageStatus::Received, 'received_at' => now()]);
                $this->sla->startIfDue($stage, GrievanceSlaStartPoint::OnReceipt);
                $stage->save();
                $this->audit->record(AuditEventType::GrievanceStageReceived, $actor, $grievance, ['stage_id' => $stage->getKey()]);
                $this->timeline->record($grievance, 'stage_received', $actor, ['stage_no' => $stage->stage_no], $stage->getKey());
                if ($grievance->status === GrievanceStatus::Appealed) {
                    $grievance->forceFill(['status' => GrievanceStatus::UnderReview])->save();
                }
            }

            return $stage;
        });
    }

    /** Formal acceptance for review (starts an on-acceptance SLA clock). */
    public function startReview(Grievance $grievance, User $actor): GrievanceCaseStage
    {
        $this->access->authorize($this->access->canReview($actor, $grievance));

        return DB::transaction(function () use ($grievance, $actor): GrievanceCaseStage {
            $grievance = $this->lock($grievance);
            $stage = $this->lockedCurrentStage($grievance);
            if (! in_array($stage->status, [GrievanceStageStatus::Pending, GrievanceStageStatus::Received], true)) {
                return $stage;
            }
            $stage->forceFill([
                'status' => GrievanceStageStatus::UnderReview,
                'received_at' => $stage->received_at ?? now(),
                'review_started_at' => now(),
            ]);
            $this->sla->startIfDue($stage, GrievanceSlaStartPoint::OnReceipt);
            $this->sla->startIfDue($stage, GrievanceSlaStartPoint::OnAcceptance);
            $stage->save();
            $grievance->forceFill(['status' => GrievanceStatus::UnderReview])->save();

            $this->audit->record(AuditEventType::GrievanceReviewStarted, $actor, $grievance, ['stage_id' => $stage->getKey()]);
            $this->timeline->record($grievance, 'review_started', $actor, ['stage_no' => $stage->stage_no], $stage->getKey());

            return $stage;
        });
    }

    /** Keep the case summary status in step with the stage's working status. */
    public function setWorkingStatus(Grievance $grievance, GrievanceStageStatus $stageStatus): void
    {
        $stage = $grievance->currentStage;
        if ($stage === null || ! $stage->isOpen()) {
            return;
        }
        $stage->forceFill(['status' => $stageStatus, 'review_started_at' => $stage->review_started_at ?? now(), 'received_at' => $stage->received_at ?? now()])->save();

        $caseStatus = match ($stageStatus) {
            GrievanceStageStatus::AwaitingInformation => GrievanceStatus::AwaitingInformation,
            GrievanceStageStatus::HearingScheduled => GrievanceStatus::HearingScheduled,
            GrievanceStageStatus::DecisionDrafting, GrievanceStageStatus::ReturnedForCorrection => GrievanceStatus::DecisionDrafting,
            GrievanceStageStatus::PendingApproval => GrievanceStatus::PendingApproval,
            default => GrievanceStatus::UnderReview,
        };
        if (! $grievance->isFinal() && $grievance->status !== GrievanceStatus::WithdrawRequested) {
            $grievance->forceFill(['status' => $caseStatus])->save();
        }
    }

    public function assignOfficer(Grievance $grievance, User $actor, User $officer, GrievanceCaseOfficerRole $role): GrievanceCaseOfficer
    {
        $this->access->authorize($this->access->canAssignOfficers($actor, $grievance));

        return DB::transaction(function () use ($grievance, $actor, $officer, $role): GrievanceCaseOfficer {
            $grievance = $this->lock($grievance);
            $stage = $this->lockedCurrentStage($grievance);

            // The officer must be placed in the handling unit, hold the
            // permission, and not be conflicted on this case.
            $placement = $this->handlers->placementOf($this->access->employeeOf($officer));
            if (! in_array((string) $stage->handler_id, $placement['unit_ids'], true) || ! $officer->can('grievances.view_assigned') || $this->access->isConflicted($officer, $grievance)) {
                throw ValidationException::withMessages(['user_id' => __('grievances.errors.officer_not_eligible')]);
            }

            $record = GrievanceCaseOfficer::query()->updateOrCreate(
                ['case_stage_id' => $stage->getKey(), 'user_id' => $officer->getKey()],
                ['grievance_id' => $grievance->getKey(), 'employee_id' => $officer->employee_id, 'role' => $role, 'assigned_by' => $actor->getKey(), 'assigned_at' => now(), 'released_at' => null],
            );
            $this->audit->record(AuditEventType::GrievanceOfficerAssigned, $actor, $grievance, ['officer_user_id' => $officer->getKey(), 'role' => $role->value]);
            $this->timeline->record($grievance, 'officer_assigned', $actor, ['officer' => $officer->name, 'role' => $role->value], $stage->getKey());
            $this->notifier->toUsers([$officer], 'new_assignment', $grievance);

            return $record;
        });
    }

    public function releaseOfficer(Grievance $grievance, User $actor, GrievanceCaseOfficer $officer): void
    {
        $this->access->authorize($this->access->canAssignOfficers($actor, $grievance) && $officer->grievance_id === $grievance->getKey());
        $officer->forceFill(['released_at' => now()])->save();
        $this->audit->record(AuditEventType::GrievanceOfficerReleased, $actor, $grievance, ['officer_user_id' => $officer->user_id]);
    }

    public function addNote(Grievance $grievance, User $actor, string $body): GrievanceNote
    {
        $this->access->authorize($this->access->canReview($actor, $grievance) || $this->access->isStageLead($actor, $grievance));

        return GrievanceNote::query()->create([
            'grievance_id' => $grievance->getKey(),
            'case_stage_id' => $grievance->current_stage_id,
            'body' => $body,
            'visibility' => 'internal',
            'author_user_id' => $actor->getKey(),
        ]);
    }

    /** @param  array{task_type: string, title: string, assigned_to_user_id?: int|null, due_at?: string|null}  $data */
    public function addTask(Grievance $grievance, User $actor, array $data): GrievanceTask
    {
        $this->access->authorize($this->access->canReview($actor, $grievance));

        return GrievanceTask::query()->create([
            'grievance_id' => $grievance->getKey(),
            'case_stage_id' => $grievance->current_stage_id,
            'task_type' => GrievanceTaskType::from($data['task_type']),
            'title' => $data['title'],
            'assigned_to_user_id' => $data['assigned_to_user_id'] ?? null,
            'due_at' => $data['due_at'] ?? null,
            'status' => GrievanceTaskStatus::Open,
            'created_by' => $actor->getKey(),
        ]);
    }

    public function completeTask(GrievanceTask $task, User $actor, GrievanceTaskStatus $status): GrievanceTask
    {
        $grievance = $task->grievance;
        $this->access->authorize($grievance !== null && ($this->access->canReview($actor, $grievance) || (int) $task->assigned_to_user_id === (int) $actor->getKey()));
        $task->forceFill(['status' => $status, 'completed_at' => $status === GrievanceTaskStatus::Open ? null : now()])->save();

        return $task;
    }

    /**
     * Handler-maintained classification: confidentiality, priority, respondent
     * and systemic-issue tagging. Tags never infer guilt.
     *
     * @param  array<string, mixed>  $data
     */
    public function classify(Grievance $grievance, User $actor, array $data): Grievance
    {
        $this->access->authorize($this->access->canLead($actor, $grievance, 'grievances.review') || $this->access->canIntake($actor, $grievance));

        $allowed = ['confidentiality_level', 'priority', 'respondent_type', 'respondent_employee_id', 'respondent_organization_unit_id', 'respondent_description', 'root_cause_category', 'systemic_issue_flag', 'corrective_action_required'];
        $changes = array_intersect_key($data, array_flip($allowed));
        $old = array_intersect_key($grievance->only(array_keys($changes)), $changes);
        $grievance->forceFill($changes)->save();
        $this->audit->record(AuditEventType::GrievanceConfigurationChanged, $actor, $grievance, collect($changes)->except('respondent_description')->all(), collect($old)->except('respondent_description')->map(fn ($v) => $v instanceof \BackedEnum ? $v->value : $v)->all());

        return $grievance;
    }

    // ── Withdrawal ───────────────────────────────────────────────────────────

    public function withdraw(Grievance $grievance, User $actor, string $reasonCode, ?string $reason): Grievance
    {
        $this->access->authorize($actor->can('grievances.withdraw') && $this->access->isComplainant($actor, $grievance));
        $this->assertReasonCode(GrievanceReasonCodeType::Withdrawal, $reasonCode);

        return DB::transaction(function () use ($grievance, $actor, $reasonCode, $reason): Grievance {
            $grievance = $this->lock($grievance);
            if ($grievance->isFinal() || $grievance->status === GrievanceStatus::Draft || $grievance->status === GrievanceStatus::WithdrawRequested) {
                throw ValidationException::withMessages(['grievance' => __('grievances.errors.cannot_withdraw')]);
            }

            $reviewStarted = $grievance->stages()->whereNotNull('review_started_at')->exists();
            $grievance->forceFill(['withdrawal_reason_code' => $reasonCode, 'withdrawal_reason' => $reason, 'withdraw_requested_at' => now()]);

            if ($reviewStarted && $this->settings->withdrawalRequiresApprovalAfterReview()) {
                $grievance->forceFill(['status' => GrievanceStatus::WithdrawRequested, 'metadata' => [...($grievance->metadata ?? []), 'status_before_withdrawal' => $grievance->status->value]])->save();
                $this->audit->record(AuditEventType::GrievanceWithdrawalRequested, $actor, $grievance, ['reason_code' => $reasonCode]);
                $this->timeline->record($grievance, 'withdrawal_requested', $actor, ['reason_code' => $reasonCode]);
                if ($stage = $grievance->currentStage) {
                    $this->notifier->toStageHandlers($stage->setRelation('grievance', $grievance), 'withdrawal_requested');
                }

                return $grievance;
            }

            $this->completeWithdrawal($grievance, $actor);

            return $grievance;
        });
    }

    public function decideWithdrawal(Grievance $grievance, User $actor, bool $approve, ?string $notes): Grievance
    {
        $this->access->authorize($this->access->canLead($actor, $grievance, 'grievances.close') || $this->access->canHandle($actor, $grievance, 'grievances.close'));

        return DB::transaction(function () use ($grievance, $actor, $approve, $notes): Grievance {
            $grievance = $this->lock($grievance);
            if ($grievance->status !== GrievanceStatus::WithdrawRequested) {
                throw ValidationException::withMessages(['grievance' => __('grievances.errors.stale')]);
            }
            if ($approve) {
                $this->completeWithdrawal($grievance, $actor);
            } else {
                $previous = GrievanceStatus::tryFrom((string) ($grievance->metadata['status_before_withdrawal'] ?? '')) ?? GrievanceStatus::UnderReview;
                $grievance->forceFill(['status' => $previous, 'withdraw_requested_at' => null])->save();
                $this->audit->record(AuditEventType::GrievanceWithdrawalRejected, $actor, $grievance, [], null, $notes);
                $this->timeline->record($grievance, 'withdrawal_rejected', $actor);
                $this->notifier->toComplainant($grievance, 'withdrawal_rejected');
            }

            return $grievance;
        });
    }

    private function completeWithdrawal(Grievance $grievance, ?User $actor): void
    {
        $this->closeCurrentStage($grievance, GrievanceStageStatus::Closed);
        $grievance->forceFill([
            'status' => GrievanceStatus::Withdrawn,
            'withdrawn_at' => now(),
            'closed_at' => now(),
            'closed_by' => $actor?->getKey(),
            'closure_reason_code' => 'WITHDRAWN',
            'record_state' => GrievanceRecordState::Closed,
            'retention_until' => $this->retentionUntil(),
            'current_handler_type' => null,
            'current_handler_id' => null,
        ])->save();
        $this->audit->record(AuditEventType::GrievanceWithdrawn, $actor, $grievance, ['reason_code' => $grievance->withdrawal_reason_code]);
        $this->timeline->record($grievance, 'withdrawn', $actor);
        $this->notifier->toComplainant($grievance, 'withdrawn');
    }

    // ── Closure, reopen, retention ───────────────────────────────────────────

    /** The complainant accepts the issued decision (closes the case). */
    public function acceptOutcome(Grievance $grievance, User $actor): Grievance
    {
        $this->access->authorize($this->access->isComplainant($actor, $grievance) && $actor->can('grievances.view_own'));

        return DB::transaction(function () use ($grievance, $actor): Grievance {
            $grievance = $this->lock($grievance);
            if ($grievance->status !== GrievanceStatus::DecisionIssued) {
                throw ValidationException::withMessages(['grievance' => __('grievances.errors.stale')]);
            }
            $this->finishClosure($grievance, $actor, 'OUTCOME_ACCEPTED', null);

            return $grievance;
        });
    }

    public function close(Grievance $grievance, User $actor, string $reasonCode, ?string $notes): Grievance
    {
        $this->access->authorize($this->access->canLead($actor, $grievance, 'grievances.close') || $this->access->canHandle($actor, $grievance, 'grievances.close'));
        $this->assertReasonCode(GrievanceReasonCodeType::Closure, $reasonCode);

        return DB::transaction(function () use ($grievance, $actor, $reasonCode, $notes): Grievance {
            $grievance = $this->lock($grievance);
            $closable = [GrievanceStatus::DecisionIssued, GrievanceStatus::ReferredExternal];
            if (! in_array($grievance->status, $closable, true)) {
                throw ValidationException::withMessages(['grievance' => __('grievances.errors.not_closable')]);
            }
            // Closing inside an open appeal window is a deliberate act the
            // reason code records; the complainant is notified either way.
            $this->finishClosure($grievance, $actor, $reasonCode, $notes);

            return $grievance;
        });
    }

    public function closeBySystem(Grievance $grievance, string $reasonCode): void
    {
        DB::transaction(function () use ($grievance, $reasonCode): void {
            $grievance = $this->lock($grievance);
            if ($grievance->status !== GrievanceStatus::DecisionIssued || $grievance->appeal_deadline_at === null || $grievance->appeal_deadline_at->isFuture()) {
                return;
            }
            $this->finishClosure($grievance, null, $reasonCode, null);
        });
    }

    private function finishClosure(Grievance $grievance, ?User $actor, string $reasonCode, ?string $notes): void
    {
        $this->closeCurrentStage($grievance, GrievanceStageStatus::Closed);
        $grievance->forceFill([
            'status' => GrievanceStatus::Closed,
            'closed_at' => now(),
            'closed_by' => $actor?->getKey(),
            'closure_reason_code' => $reasonCode,
            'closure_notes' => $notes,
            'resolved_at' => $grievance->resolved_at ?? now(),
            'record_state' => GrievanceRecordState::Closed,
            'retention_until' => $this->retentionUntil(),
            'current_handler_type' => null,
            'current_handler_id' => null,
        ])->save();
        $this->audit->record(AuditEventType::GrievanceClosed, $actor, $grievance, ['reason_code' => $reasonCode]);
        $this->timeline->record($grievance, 'closed', $actor, ['reason_code' => $reasonCode]);
        $this->notifier->toComplainant($grievance, 'closed');
    }

    public function reopen(Grievance $grievance, User $actor, string $reasonCode, string $reason): Grievance
    {
        $this->access->authorize($actor->can('grievances.reopen') && ($this->access->isOversight($actor, $grievance) || $this->access->handledAnyStage($actor, $grievance)));
        $this->assertReasonCode(GrievanceReasonCodeType::Reopen, $reasonCode);
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => __('grievances.errors.reason_required')]);
        }

        return DB::transaction(function () use ($grievance, $actor, $reasonCode, $reason): Grievance {
            $grievance = $this->lock($grievance);
            if (! in_array($grievance->status, [GrievanceStatus::Closed, GrievanceStatus::DecisionIssued], true) || $grievance->record_state === GrievanceRecordState::Archived) {
                throw ValidationException::withMessages(['grievance' => __('grievances.errors.not_reopenable')]);
            }
            $last = $grievance->stages()->reorder()->orderByDesc('stage_no')->first();
            if ($last === null || $last->handler_type === GrievanceHandlerType::Organization) {
                throw ValidationException::withMessages(['grievance' => __('grievances.errors.not_reopenable')]);
            }

            $grievance->forceFill([
                'closed_at' => null, 'closed_by' => null, 'closure_reason_code' => null,
                'record_state' => GrievanceRecordState::Active, 'retention_until' => null, 'appeal_deadline_at' => null,
                'reopened_count' => $grievance->reopened_count + 1,
            ])->save();
            // Reopening returns the case to the last handler as a new stage.
            $last->forceFill(['is_current' => false])->save();
            $this->routing->createStage($grievance, $last->handler_type, (string) $last->handler_id, GrievanceMovementType::Other, null, null, $reasonCode.': '.$reason, $actor);

            $this->audit->record(AuditEventType::GrievanceReopened, $actor, $grievance, ['reason_code' => $reasonCode], null, $reason);
            $this->timeline->record($grievance, 'reopened', $actor, ['reason_code' => $reasonCode]);
            $this->notifier->toComplainant($grievance, 'reopened');

            return $grievance;
        });
    }

    public function archive(Grievance $grievance, User $actor): Grievance
    {
        $this->access->authorize($actor->can('grievances.archive') && $this->access->isOversight($actor, $grievance));

        return DB::transaction(function () use ($grievance, $actor): Grievance {
            $grievance = $this->lock($grievance);
            if ($grievance->record_state !== GrievanceRecordState::Closed || $grievance->legal_hold) {
                throw ValidationException::withMessages(['grievance' => __('grievances.errors.not_archivable')]);
            }
            if ($grievance->retention_until === null || $grievance->retention_until->isFuture()) {
                throw ValidationException::withMessages(['grievance' => __('grievances.errors.retention_not_reached')]);
            }
            $grievance->forceFill(['record_state' => GrievanceRecordState::Archived, 'archived_at' => now()])->save();
            $this->audit->record(AuditEventType::GrievanceArchived, $actor, $grievance, ['retention_until' => $grievance->retention_until?->toDateString()]);

            return $grievance;
        });
    }

    public function setLegalHold(Grievance $grievance, User $actor, bool $hold, string $reason): Grievance
    {
        $this->access->authorize($actor->can('grievances.archive') && $this->access->isOversight($actor, $grievance));
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => __('grievances.errors.reason_required')]);
        }
        $grievance->forceFill(['legal_hold' => $hold, 'legal_hold_reason' => $reason])->save();
        $this->audit->record(AuditEventType::GrievanceLegalHoldChanged, $actor, $grievance, ['legal_hold' => $hold], null, $reason);

        return $grievance;
    }

    // ── Outcomes ─────────────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $data */
    public function recordCorrectiveAction(Grievance $grievance, User $actor, array $data): GrievanceCorrectiveAction
    {
        $this->access->authorize($this->access->canReview($actor, $grievance) || $this->access->canLead($actor, $grievance, 'grievances.review'));

        $action = GrievanceCorrectiveAction::query()->create([
            'grievance_id' => $grievance->getKey(),
            'decision_id' => $data['decision_id'] ?? null,
            'description' => $data['description'],
            'responsible_organization_id' => $data['responsible_organization_id'] ?? $grievance->organization_id,
            'responsible_organization_unit_id' => $data['responsible_organization_unit_id'] ?? null,
            'due_date' => $data['due_date'] ?? null,
            'status' => GrievanceCorrectiveActionStatus::Open,
            'created_by' => $actor->getKey(),
        ]);
        $grievance->forceFill(['corrective_action_required' => true])->save();
        $this->audit->record(AuditEventType::GrievanceCorrectiveActionRecorded, $actor, $action, ['status' => 'open']);

        return $action;
    }

    /** @param  array<string, mixed>  $data */
    public function updateCorrectiveAction(GrievanceCorrectiveAction $action, User $actor, array $data): GrievanceCorrectiveAction
    {
        $grievance = $action->grievance;
        $this->access->authorize($grievance !== null && ($this->access->canReview($actor, $grievance) || $this->access->handledAnyStage($actor, $grievance) && $actor->can('grievances.review')));
        $status = GrievanceCorrectiveActionStatus::from($data['status']);
        $action->forceFill([
            'status' => $status,
            'completion_notes' => $data['completion_notes'] ?? $action->completion_notes,
            'completion_evidence_id' => $data['completion_evidence_id'] ?? $action->completion_evidence_id,
            'completed_at' => $status === GrievanceCorrectiveActionStatus::Completed ? now() : null,
            'completed_by' => $status === GrievanceCorrectiveActionStatus::Completed ? $actor->getKey() : null,
        ])->save();
        $this->audit->record(AuditEventType::GrievanceCorrectiveActionRecorded, $actor, $action, ['status' => $status->value]);

        return $action;
    }

    /**
     * An explicit referral to the disciplinary process. The grievance is
     * never converted into a disciplinary case; the link is kept here.
     *
     * @param  array<string, mixed>  $data
     */
    public function referToDisciplinary(Grievance $grievance, User $actor, array $data): GrievanceDisciplinaryReferral
    {
        $this->access->authorize($this->access->canLead($actor, $grievance, 'grievances.review'));

        $referral = GrievanceDisciplinaryReferral::query()->create([
            'grievance_id' => $grievance->getKey(),
            'decision_id' => $data['decision_id'] ?? null,
            'referred_to_organization_id' => $data['referred_to_organization_id'] ?? $grievance->organization_id,
            'referred_to_organization_unit_id' => $data['referred_to_organization_unit_id'] ?? null,
            'reason' => $data['reason'],
            'status' => GrievanceReferralStatus::Referred,
            'referred_by' => $actor->getKey(),
            'referred_at' => now(),
        ]);
        $this->audit->record(AuditEventType::GrievanceDisciplinaryReferralCreated, $actor, $referral, ['referral_id' => $referral->getKey()]);
        $this->timeline->record($grievance, 'disciplinary_referral', $actor);

        return $referral;
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    public function lock(Grievance $grievance): Grievance
    {
        return Grievance::query()->whereKey($grievance->getKey())->lockForUpdate()->firstOrFail();
    }

    public function lockedCurrentStage(Grievance $grievance): GrievanceCaseStage
    {
        $stage = $grievance->current_stage_id
            ? GrievanceCaseStage::query()->whereKey($grievance->current_stage_id)->lockForUpdate()->first()
            : null;
        if ($stage === null || ! $stage->is_current) {
            throw ValidationException::withMessages(['grievance' => __('grievances.errors.no_current_stage')]);
        }

        return $stage;
    }

    public function closeCurrentStage(Grievance $grievance, GrievanceStageStatus $status): void
    {
        if ($grievance->current_stage_id === null) {
            return;
        }
        GrievanceCaseStage::query()->whereKey($grievance->current_stage_id)->where('is_current', true)
            ->update(['status' => $status->value, 'completed_at' => now(), 'is_current' => false]);
    }

    public function assertReasonCode(GrievanceReasonCodeType $type, string $code): void
    {
        if (! GrievanceReasonCode::query()->where('type', $type->value)->where('code', $code)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['reason_code' => __('grievances.errors.invalid_reason_code')]);
        }
    }

    private function activeCategory(?string $categoryId): GrievanceCategory
    {
        $category = $categoryId ? GrievanceCategory::query()->whereKey($categoryId)->where('is_active', true)->first() : null;
        if ($category === null) {
            throw ValidationException::withMessages(['category_id' => __('grievances.errors.invalid_category')]);
        }

        return $category;
    }

    private function retentionUntil(): ?string
    {
        $years = $this->settings->retentionYears();

        return $years > 0 ? now()->addYears($years)->toDateString() : null;
    }
}
