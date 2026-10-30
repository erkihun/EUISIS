<?php

declare(strict_types=1);

namespace Modules\Discipline\Application\Services;

use App\Enums\CommitteeType;
use App\Models\Employee;
use App\Models\GrievanceCommittee;
use App\Models\StatutoryDeadline;
use App\Models\User;
use App\Services\Legal\StatutoryCommitteeRules;
use App\Support\Legal\Regulation201;
use Modules\Discipline\Contracts\Data\DisciplinaryCaseStatus;
use Modules\Discipline\Domain\Enums\DisciplinaryCaseRole;
use Modules\Discipline\Domain\Enums\DisciplinaryTrack;
use Modules\Discipline\Domain\Models\DisciplinaryCase;
use Modules\Discipline\Domain\Models\DisciplinaryCaseMember;
use Modules\Discipline\Domain\Models\DisciplinaryCharge;
use Modules\Discipline\Domain\Models\DisciplinaryDecision;
use Modules\Discipline\Domain\Models\DisciplinaryEvidence;
use Modules\Discipline\Domain\Models\DisciplinaryRecommendation;
use Modules\Discipline\Domain\Models\DisciplinaryResponse;
use Modules\Discipline\Domain\Models\DisciplinarySuspension;
use Modules\Discipline\Domain\Models\DisciplinaryWitness;

/**
 * Shapes disciplinary cases for Inertia. The accused's view leaves out the
 * committee's internal deliberation (votes, dissents, draft recommendation)
 * and staff-only notes; abilities are computed here, never in the browser.
 */
final class DisciplinaryCasePresenter
{
    public function __construct(
        private readonly DisciplinaryCaseAccess $access,
        private readonly StatutoryCommitteeRules $committees,
    ) {}

    /** @return array<string, mixed> */
    public function row(DisciplinaryCase $case, string $locale): array
    {
        return [
            'id' => $case->getKey(),
            'case_number' => $case->case_number,
            'status' => $case->status->value,
            'track' => $case->track->value,
            'severity' => $case->severity->value,
            // Transitional provision: a case begun under the earlier regulation stays under it.
            'legacy_regulation' => ($case->governing_regulation ?? Regulation201::CODE) !== Regulation201::CODE,
            'accused' => $this->employee($case->accused),
            'organization' => $case->organization ? ['id' => $case->organization->getKey(), 'name' => $this->name($case->organization, $locale)] : null,
            'charged_at' => $case->charged_at?->toIso8601String(),
            'next_deadline' => $this->nextDeadline($case, $locale),
            'created_at' => $case->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function detail(DisciplinaryCase $case, User $viewer, string $locale): array
    {
        $case->loadMissing(['accused', 'organization', 'organizationUnit', 'position', 'committee', 'members.employee', 'charges', 'responses', 'evidence', 'witnesses', 'recommendations.votes', 'decisions.appeal', 'suspensions', 'history.actor', 'deadlines']);
        $asAccused = $this->access->isAccused($viewer, $case);

        $data = [
            ...$this->row($case, $locale),
            'summary' => $case->summary,
            'unit' => $case->organizationUnit ? $this->name($case->organizationUnit, $locale) : null,
            'position' => $case->position ? ($locale === 'am' ? ($case->position->title_am ?? $case->position->title_en) : $case->position->title_en) : null,
            'source_type' => $asAccused ? null : $case->source_type,
            'summons' => [
                'served_at' => $case->summons_served_at?->toIso8601String(),
                'method' => $case->summons_method,
                'hearing_at' => $case->hearing_at?->toIso8601String(),
                'hearing_location' => $case->hearing_location,
            ],
            'in_absentia' => $case->in_absentia,
            'additional_evidence_required' => $case->additional_evidence_required,
            'final_statement' => [
                'text' => $case->final_statement,
                'declined' => $case->final_statement_declined,
                'at' => $case->final_statement_at?->toIso8601String(),
            ],
            'charges' => $case->charges->map(fn (DisciplinaryCharge $c): array => [
                'id' => $c->getKey(), 'sequence' => $c->sequence, 'title' => $c->title, 'particulars' => $c->particulars,
                'occurred_on' => $c->occurred_on?->toDateString(), 'occurred_place' => $c->occurred_place,
                'violated_provision' => $c->violated_provision, 'evidence_list' => $c->evidence_list,
                'plea' => $c->plea?->value, 'plea_statement' => $c->plea_statement,
            ])->values(),
            'responses' => $case->responses->map(fn (DisciplinaryResponse $r): array => [
                'id' => $r->getKey(), 'version' => $r->version, 'objection_ground' => $r->objection_ground?->value,
                'objection_text' => $r->objection_text, 'objection_decision' => $r->objection_decision,
                'defense_evidence_list' => $r->defense_evidence_list, 'acknowledged_name' => $r->acknowledged_name, 'pleas' => $r->pleas ?? [],
                'received_at' => $r->received_at?->toIso8601String(),
            ])->values(),
            'evidence' => $case->evidence->map(fn (DisciplinaryEvidence $e): array => [
                'id' => $e->getKey(), 'side' => $e->side->value, 'title' => $e->title, 'description' => $e->description,
                'held_by' => $e->held_by, 'has_file' => $e->path !== null, 'original_name' => $e->original_name,
                'size_bytes' => $e->size_bytes, 'created_at' => $e->created_at?->toIso8601String(),
            ])->values(),
            'witnesses' => $case->witnesses->map(fn (DisciplinaryWitness $w): array => [
                'id' => $w->getKey(), 'called_by' => $w->called_by->value, 'name' => $w->name, 'age' => $w->age,
                'occupation' => $w->occupation, 'relationship_disclosure' => $w->relationship_disclosure,
                'statement' => $w->statement, 'cross_examination' => $w->cross_examination,
                'written_testimony' => $w->written_testimony, 'heard_at' => $w->heard_at?->toIso8601String(),
            ])->values(),
            'decisions' => $case->decisions
                ->filter(fn (DisciplinaryDecision $d): bool => ! $asAccused || $d->delivered_at !== null)
                ->map(fn (DisciplinaryDecision $d): array => [
                    'id' => $d->getKey(), 'action' => $d->action->value, 'finding' => $d->finding?->value,
                    'penalty' => $d->penalty?->value, 'penalty_amount' => $d->penalty_amount, 'penalty_unit' => $d->penalty?->amountUnit(),
                    'reason' => $d->reason, 'decided_at' => $d->decided_at?->toIso8601String(),
                    'delivered_at' => $d->delivered_at?->toIso8601String(), 'delivery_method' => $d->delivery_method,
                    'effective_on' => $d->effective_on?->toDateString(),
                    // Art. 29: while an appeal is pending the penalty is not executed.
                    'execution_stayed' => $d->appeal !== null && $d->appeal->isPending(),
                    'appeal' => $d->appeal === null ? null : [
                        'notice_received_at' => $d->appeal->notice_received_at?->toIso8601String(),
                        'record_copy_requested' => $d->appeal->record_copy_requested,
                        'record_copy_delivered_at' => $d->appeal->record_copy_delivered_at?->toIso8601String(),
                        'filed_on' => $d->appeal->filed_on?->toDateString(),
                        'court_case_number' => $d->appeal->court_case_number,
                        'outcome' => $d->appeal->outcome?->value,
                        'outcome_decided_on' => $d->appeal->outcome_decided_on?->toDateString(),
                        'outcome_received_at' => $d->appeal->outcome_received_at?->toIso8601String(),
                        'outcome_summary' => $d->appeal->outcome_summary,
                        'implemented_at' => $d->appeal->implemented_at?->toIso8601String(),
                        'implementation_note' => $d->appeal->implementation_note,
                    ],
                ])->values(),
            // Art. 12(1): the employee is told the reason in writing, so the accused sees it too.
            'suspensions' => $case->suspensions->sortBy('starts_on')->map(fn (DisciplinarySuspension $s): array => [
                'id' => $s->getKey(), 'reason' => $s->reason, 'without_pay' => $s->without_pay,
                'starts_on' => $s->starts_on?->toDateString(), 'ends_on' => $s->ends_on?->toDateString(),
                'served_at' => $s->served_at?->toIso8601String(), 'service_method' => $s->service_method,
                'posting_ends_on' => $s->posting_ends_on?->toDateString(), 'in_force' => $s->isInForce(),
                'lifted_on' => $s->lifted_on?->toDateString(), 'lift_reason' => $s->lift_reason,
                'back_pay_status' => $s->back_pay_status, 'back_pay_paid_on' => $s->back_pay_paid_on?->toDateString(),
            ])->values(),
            'deadlines' => $case->deadlines->sortBy('started_at')->map(fn (StatutoryDeadline $d): array => $d->toPresentation($locale))->values(),
        ];

        if ($asAccused) {
            return $data;
        }

        return [
            ...$data,
            'committee' => $case->committee ? ['id' => $case->committee->getKey(), 'name' => $this->name($case->committee, $locale)] : null,
            'members' => $case->members->map(fn (DisciplinaryCaseMember $m): array => [
                'id' => $m->getKey(), 'employee' => $this->employee($m->employee), 'role' => $m->role->value,
                'selection_source' => $m->selection_source, 'is_active' => $m->is_active,
                'recused_at' => $m->recused_at?->toIso8601String(), 'recusal_reason' => $m->recusal_reason,
                'replaces_member_id' => $m->replaces_member_id,
            ])->values(),
            'recommendations' => $case->recommendations->map(fn (DisciplinaryRecommendation $r): array => [
                'id' => $r->getKey(), 'version' => $r->version, 'status' => $r->status, 'finding' => $r->finding->value,
                'penalty' => $r->recommended_penalty?->value, 'penalty_amount' => $r->penalty_amount, 'penalty_unit' => $r->recommended_penalty?->amountUnit(),
                'gravity_assessment' => $r->gravity_assessment, 'conduct_assessment' => $r->conduct_assessment,
                'prior_record_assessment' => $r->prior_record_assessment, 'reasoning' => $r->reasoning,
                'members_present' => $r->members_present, 'votes_for' => $r->votes_for, 'votes_against' => $r->votes_against,
                'tie_broken_by_chair' => $r->tie_broken_by_chair, 'adopted_at' => $r->adopted_at?->toIso8601String(),
                'dissents' => $r->votes->whereNotNull('dissent_reason')->map(fn ($v): array => ['member_id' => $v->case_member_id, 'reason' => $v->dissent_reason])->values(),
            ])->values(),
            'history' => $case->history->map(fn ($h): array => [
                'from' => $h->from_status, 'to' => $h->to_status, 'actor' => $h->actor?->name, 'reason' => $h->reason,
                'at' => $h->created_at?->toIso8601String(),
            ])->values(),
        ];
    }

    /**
     * What the viewer may do now. The service re-checks every one of these.
     *
     * @return array<string, bool>
     */
    public function abilities(DisciplinaryCase $case, User $user): array
    {
        $status = $case->status;
        $open = in_array($status, [DisciplinaryCaseStatus::Draft, DisciplinaryCaseStatus::Charged, DisciplinaryCaseStatus::UnderInvestigation], true);
        $investigating = $status->isInvestigating() && $case->report_submitted_at === null;
        $canInvestigate = $this->access->canInvestigate($user, $case);
        $asAccused = $this->access->canRespondAsAccused($user, $case);
        $latest = $case->latestResponse();
        $decision = $status === DisciplinaryCaseStatus::DecisionDelivered ? $case->currentDecision() : null;
        $appeal = $decision === null ? null : $case->currentAppeal();
        $manage = $this->access->canOperate($user, $case, 'discipline.manage');
        $headActs = $this->access->canOperate($user, $case, 'discipline.decide') && $this->access->seat($user, $case) === null;
        $inForce = $case->suspensionInForce();
        $appealBlocksClose = $appeal !== null && ($appeal->isPending() || ($appeal->outcome->requiresImplementation() && $appeal->implemented_at === null));

        return [
            'addCharge' => $status === DisciplinaryCaseStatus::Draft && $this->access->canOperate($user, $case, 'discipline.create'),
            'assignPanel' => $status === DisciplinaryCaseStatus::Draft && $this->access->canOperate($user, $case, 'discipline.manage'),
            'fileCharge' => $status === DisciplinaryCaseStatus::Draft && $this->access->canOperate($user, $case, 'discipline.charge'),
            'serveSummons' => $status === DisciplinaryCaseStatus::Charged && $case->track === DisciplinaryTrack::Formal && $this->access->canOperate($user, $case, 'discipline.charge'),
            'recordResponse' => $investigating && ($canInvestigate || $asAccused),
            'decideObjection' => $canInvestigate && $latest?->objection_ground !== null && $latest->objection_decision === null,
            'proceedInAbsentia' => $investigating && $canInvestigate && ! $case->in_absentia,
            'addEvidence' => $open && ($canInvestigate || $asAccused),
            'recordWitness' => $status === DisciplinaryCaseStatus::UnderInvestigation && $canInvestigate,
            'recuse' => ! $status->isFinal() && $this->access->canOperate($user, $case, 'discipline.manage'),
            'finalStatement' => $status === DisciplinaryCaseStatus::UnderInvestigation && ($canInvestigate || $asAccused),
            'extend' => $investigating && $case->track === DisciplinaryTrack::Formal && ! $case->additional_evidence_required && $canInvestigate,
            'recommend' => $status === DisciplinaryCaseStatus::UnderInvestigation && $this->access->canRecommend($user, $case),
            'decide' => $status === DisciplinaryCaseStatus::ReportSubmitted && $this->access->canOperate($user, $case, 'discipline.decide') && $this->access->seat($user, $case) === null,
            'deliver' => $status === DisciplinaryCaseStatus::Decided && $this->access->canOperate($user, $case, 'discipline.deliver_decision'),
            'discontinue' => $open && $this->access->canOperate($user, $case, 'discipline.charge'),
            'terminate' => ! $status->isFinal() && ! in_array($status, [DisciplinaryCaseStatus::Decided, DisciplinaryCaseStatus::DecisionDelivered], true) && $this->access->canOperate($user, $case, 'discipline.manage'),
            'close' => $status === DisciplinaryCaseStatus::DecisionDelivered && $manage && ! $appealBlocksClose,
            // Regulation 201/2018 Art. 12: suspension pending the case.
            'suspend' => $headActs && $inForce === null && ! $status->isFinal() && $status !== DisciplinaryCaseStatus::DecisionDelivered,
            'liftSuspension' => $headActs && $inForce !== null,
            'suspensionBackPay' => $manage && $case->suspensions()->where('back_pay_status', DisciplinarySuspension::BACK_PAY_DUE)->exists(),
            // Regulation 201/2018 Part Five: the employee's appeal to the Administrative Court.
            'appealNotice' => $decision?->penalty !== null && $appeal === null && $manage,
            'appealRecordCopy' => $appeal !== null && $appeal->isPending() && $appeal->record_copy_requested && $appeal->record_copy_delivered_at === null
                && $this->access->canOperate($user, $case, 'discipline.deliver_decision'),
            'appealFiling' => $appeal !== null && $appeal->isPending() && $appeal->filed_on === null && $manage,
            'appealOutcome' => $appeal !== null && $appeal->isPending() && $manage,
            'appealImplemented' => $appeal !== null && $appeal->outcome?->requiresImplementation() === true && $appeal->implemented_at === null && $manage,
        ];
    }

    /**
     * Committees an officer may seat on a formal case, with any Art. 5 problems.
     *
     * @return list<array<string, mixed>>
     */
    public function committeeOptions(DisciplinaryCase $case, string $locale): array
    {
        return GrievanceCommittee::query()
            ->where('committee_type', CommitteeType::Disciplinary->value)
            ->where('organization_id', $case->organization_id)
            ->where('status', 'active')
            ->orderBy('name_en')
            ->get()
            ->map(fn (GrievanceCommittee $c): array => [
                'id' => $c->getKey(),
                'name' => $this->name($c, $locale),
                'problems' => $this->committees->compositionProblems($c),
            ])->values()->all();
    }

    /** @return list<array{id: string, employee: array<string, mixed>|null, role: string}> */
    public function votingMembers(DisciplinaryCase $case): array
    {
        return $case->members()->seated()->with('employee')
            ->where('role', '!=', DisciplinaryCaseRole::Investigator->value)
            ->get()
            ->map(fn (DisciplinaryCaseMember $m): array => ['id' => $m->getKey(), 'employee' => $this->employee($m->employee), 'role' => $m->role->value])
            ->values()->all();
    }

    /** @return array<string, mixed>|null */
    private function nextDeadline(DisciplinaryCase $case, string $locale): ?array
    {
        $deadline = $case->deadlines()->whereNull('completed_at')->whereNull('superseded_at')->orderBy('due_at')->first();

        return $deadline?->toPresentation($locale);
    }

    /** @return array{id: string, name: string|null, employee_number: string|null}|null */
    private function employee(?Employee $employee): ?array
    {
        return $employee === null ? null : [
            'id' => $employee->getKey(),
            'name' => $employee->name_en ?? $employee->full_name,
            'name_am' => $employee->full_name ?? $employee->name_en,
            'employee_number' => $employee->employee_number,
        ];
    }

    private function name(object $model, string $locale): string
    {
        return (string) ($locale === 'am' ? ($model->name_am ?? $model->name_en) : $model->name_en);
    }
}
