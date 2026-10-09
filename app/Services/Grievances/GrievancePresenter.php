<?php

declare(strict_types=1);

namespace App\Services\Grievances;

use App\Enums\Grievance\GrievanceConfidentiality;
use App\Enums\Grievance\GrievanceEventVisibility;
use App\Enums\Grievance\GrievanceLetterStatus;
use App\Enums\Grievance\GrievanceSlaPauseStatus;
use App\Models\Employee;
use App\Models\Grievance;
use App\Models\GrievanceCaseEvent;
use App\Models\GrievanceCaseStage;
use App\Models\GrievanceDecision;
use App\Models\GrievanceEvidence;
use App\Models\GrievanceLetter;
use App\Models\User;

/**
 * Shapes grievance data for Inertia pages. Two audiences:
 *
 *  - handlers/oversight: full case detail as far as their access allows
 *  - complainant: safe progress only — never internal notes, drafts,
 *    approval comments, panel identities, other employees' data or
 *    evidence they did not submit.
 */
final class GrievancePresenter
{
    public function __construct(
        private readonly GrievanceHandlerRegistry $handlers,
        private readonly GrievanceSlaService $sla,
    ) {}

    /** Relations a list row needs (eager-loaded by the controller). */
    public static function listRelations(): array
    {
        return [
            'category:id,name_en,name_am',
            'organization:id,name_en,name_am',
            'employee:id,full_name,name_en,employee_number',
            'currentStage' => fn ($q) => $q->withExists(['pauses as is_paused' => fn ($p) => $p->where('status', GrievanceSlaPauseStatus::Active->value)]),
        ];
    }

    /** @return array<string, mixed> */
    public function listRow(Grievance $g, bool $detailsVisible = true): array
    {
        $stage = $g->currentStage;
        $hidden = ! $detailsVisible;

        return [
            'id' => $g->getKey(),
            'reference_number' => $g->reference_number,
            'subject' => $hidden ? null : $g->subject,
            'status' => $g->status?->value,
            'priority' => $g->priority?->value,
            'confidentiality_level' => $g->confidentiality_level?->value,
            'category' => $g->category?->only(['id', 'name_en', 'name_am']),
            'organization' => $g->organization?->only(['id', 'name_en', 'name_am']),
            'employee' => $hidden || $g->employee === null ? null : $this->employee($g->employee),
            'handler' => $g->current_handler_type ? $this->handler($g->current_handler_type->value, $g->current_handler_id) : null,
            'stage_no' => $stage?->stage_no,
            'stage_status' => $stage?->status?->value,
            'sla' => $stage ? $this->slaFor($stage) : null,
            'submitted_at' => $g->submitted_at?->toIso8601String(),
            'created_at' => $g->created_at?->toIso8601String(),
            'appeal_deadline_at' => $g->appeal_deadline_at?->toIso8601String(),
            'record_state' => $g->record_state?->value,
        ];
    }

    /** @return array<string, mixed> */
    public function slaFor(GrievanceCaseStage $stage): array
    {
        $paused = $stage->getAttribute('is_paused');

        return [
            ...$this->sla->summary($stage),
            'state' => $this->sla->state($stage, $paused === null ? null : (bool) $paused)->value,
        ];
    }

    /** @return array<string, mixed> */
    public function stage(GrievanceCaseStage $stage, bool $withPanel): array
    {
        return [
            'id' => $stage->getKey(),
            'stage_no' => $stage->stage_no,
            'handler' => $this->handler($stage->handler_type->value, $stage->handler_id),
            'movement_type' => $stage->movement_type?->value,
            'movement_reason' => $withPanel ? $stage->movement_reason : null,
            'status' => $stage->status?->value,
            'is_current' => $stage->is_current,
            'received_at' => $stage->received_at?->toIso8601String(),
            'review_started_at' => $stage->review_started_at?->toIso8601String(),
            'completed_at' => $stage->completed_at?->toIso8601String(),
            'escalated_at' => $stage->escalated_at?->toIso8601String(),
            'created_at' => $stage->created_at?->toIso8601String(),
            'sla' => $this->slaFor($stage),
            'panel' => $withPanel ? $stage->members->map(fn ($m) => [
                'id' => $m->getKey(),
                'employee' => $m->employee ? $this->employee($m->employee) : null,
                'role' => $m->role?->value,
                'source' => $m->source,
                'is_active' => $m->is_active,
                'recused_at' => $m->recused_at?->toIso8601String(),
                'left_at' => $m->left_at?->toIso8601String(),
            ])->values()->all() : [],
            'officers' => $withPanel ? $stage->officers->map(fn ($o) => [
                'id' => $o->getKey(),
                'user_id' => $o->user_id,
                'name' => $o->employee ? ($o->employee->name_en ?: $o->employee->full_name) : $o->user?->name,
                'name_am' => $o->employee?->full_name,
                'role' => $o->role?->value,
                'assigned_at' => $o->assigned_at?->toIso8601String(),
                'released_at' => $o->released_at?->toIso8601String(),
            ])->values()->all() : [],
        ];
    }

    /** @return array<string, mixed> */
    public function handler(string $type, ?string $id): array
    {
        $d = $this->handlers->describe($type, $id);

        return ['type' => $d['type'], 'id' => $d['id'], 'name_en' => $d['name_en'], 'name_am' => $d['name_am'], 'organization_name_en' => $d['organization_name_en'], 'organization_name_am' => $d['organization_name_am']];
    }

    /** @return array{id: string, name: string|null, name_en: string|null, employee_number: string|null} */
    public function employee(Employee $e): array
    {
        return ['id' => $e->getKey(), 'name' => $e->full_name, 'name_en' => $e->name_en ?: $e->full_name, 'employee_number' => $e->employee_number];
    }

    /** @return array<string, mixed> */
    public function event(GrievanceCaseEvent $e, bool $withActor): array
    {
        return [
            'id' => $e->getKey(),
            'event' => $e->event,
            'visibility' => $e->visibility?->value,
            'data' => $e->data ?? [],
            'occurred_at' => $e->occurred_at?->toIso8601String(),
            'actor' => $withActor ? $e->actor?->name : null,
            'stage_id' => $e->case_stage_id,
        ];
    }

    /** @return array<string, mixed> */
    public function decision(GrievanceDecision $d, bool $withApprovals): array
    {
        return [
            'id' => $d->getKey(),
            'version_no' => $d->version_no,
            'decision_no' => $d->decision_no,
            'decision_type' => $d->decision_type?->value,
            'status' => $d->status?->value,
            'findings' => $d->findings,
            'facts_considered' => $d->facts_considered,
            'legal_basis' => $d->legal_basis,
            'analysis' => $d->analysis,
            'decision_text' => $d->decision_text,
            'recommendations' => $d->recommendations,
            'corrective_action_required' => $d->corrective_action_required,
            'disciplinary_referral_recommended' => $d->disciplinary_referral_recommended,
            'requires_executive_approval' => $d->requires_executive_approval,
            'quorum_met' => $d->quorum_met,
            'stage_id' => $d->case_stage_id,
            'prepared_by' => $d->preparer?->name,
            'approver_position' => $d->approverPosition ? ['title_en' => $d->approverPosition->title_en, 'title_am' => $d->approverPosition->title_am] : null,
            'approval_due_at' => $d->approval_due_at?->toIso8601String(),
            'submitted_for_approval_at' => $d->submitted_for_approval_at?->toIso8601String(),
            'approved_at' => $d->approved_at?->toIso8601String(),
            'finalized_at' => $d->finalized_at?->toIso8601String(),
            'issued_at' => $d->issued_at?->toIso8601String(),
            'created_at' => $d->created_at?->toIso8601String(),
            'supersedes_decision_id' => $d->supersedes_decision_id,
            'approvals' => $withApprovals ? $d->approvals->map(fn ($a) => [
                'id' => $a->getKey(),
                'action' => $a->action?->value,
                'actor' => $a->actor?->name,
                'comment' => $a->comment,
                'delegated' => $a->delegation_id !== null,
                'acted_at' => $a->acted_at?->toIso8601String(),
            ])->values()->all() : [],
            'votes' => $withApprovals ? $d->votes->map(fn ($v) => [
                'employee' => $v->employee ? $this->employee($v->employee) : null,
                'vote' => $v->vote?->value,
                'opinion' => $v->opinion,
                'voted_at' => $v->voted_at?->toIso8601String(),
            ])->values()->all() : [],
        ];
    }

    /** @return array<string, mixed> */
    public function evidence(GrievanceEvidence $e): array
    {
        return [
            'id' => $e->getKey(),
            'evidence_type' => $e->evidence_type?->value,
            'title' => $e->title,
            'description' => $e->description,
            'original_name' => $e->original_name,
            'mime_type' => $e->mime_type,
            'size_bytes' => $e->size_bytes,
            'sha256' => $e->sha256,
            'classification' => $e->classification?->value,
            'status' => $e->status?->value,
            'scan_status' => $e->scan_status,
            'version_no' => $e->version_no,
            'supersedes_evidence_id' => $e->supersedes_evidence_id,
            'submitted_by_complainant' => $e->submitted_by_complainant,
            'submitted_by' => $e->submitter?->name,
            'submitted_at' => $e->submitted_at?->toIso8601String(),
            'information_request_id' => $e->information_request_id,
            'appeal_id' => $e->appeal_id,
        ];
    }

    /** @return array<string, mixed> */
    public function letter(GrievanceLetter $l, bool $full): array
    {
        return [
            'id' => $l->getKey(),
            'letter_type' => $l->letter_type?->value,
            'language' => $l->language?->value,
            'reference_number' => $l->reference_number,
            'subject' => $l->subject,
            'body' => $full ? $l->body : null,
            'letter_date' => $l->letter_date?->toDateString(),
            'status' => $l->status?->value,
            'signature_method' => $l->signature_method?->value,
            'signatory' => $l->signatoryEmployee ? ($l->signatoryEmployee->name_en ?: $l->signatoryEmployee->full_name) : null,
            'signatory_am' => $l->signatoryEmployee?->full_name,
            'signed_at' => $l->signed_at?->toIso8601String(),
            'sealed' => $l->seal_id !== null,
            'issued_at' => $l->issued_at?->toIso8601String(),
            'voided_at' => $l->voided_at?->toIso8601String(),
            'void_reason' => $full ? $l->void_reason : null,
            'visible_to_complainant' => $l->visible_to_complainant,
            'has_pdf' => $l->pdf_path !== null,
            'pdf_sha256' => $full ? $l->pdf_sha256 : null,
            'organization_id' => $l->organization_id,
            'decision_id' => $l->decision_id,
            'supersedes_letter_id' => $l->supersedes_letter_id,
            'recipients' => $full ? $l->recipients->map(fn ($r) => $r->only(['id', 'kind', 'recipient_type', 'recipient_id', 'name', 'position_title', 'organization_name', 'address', 'email']))->values()->all() : [],
            'attachments' => $full ? $l->attachments->map(fn ($a) => $a->only(['id', 'attachment_type', 'title', 'evidence_id', 'reference_id']))->values()->all() : [],
            'dispatches' => $full ? $l->dispatches->map(fn ($d) => [
                'id' => $d->getKey(), 'channel' => $d->channel?->value, 'status' => $d->status?->value, 'destination' => $d->destination,
                'sent_at' => $d->sent_at?->toIso8601String(), 'delivered_at' => $d->delivered_at?->toIso8601String(),
                'failed_at' => $d->failed_at?->toIso8601String(), 'failure_reason' => $d->failure_reason, 'acknowledged_at' => $d->acknowledged_at?->toIso8601String(),
            ])->values()->all() : [],
        ];
    }

    /**
     * The complainant's view of their own case (My Portal).
     *
     * @return array<string, mixed>
     */
    public function forComplainant(Grievance $g, User $user): array
    {
        $g->loadMissing(['category', 'organization', 'currentStage', 'stages', 'informationRequests.responses', 'appeals']);
        $stage = $g->currentStage;

        return [
            ...$this->listRow($g),
            'description' => $g->description,
            'incident_date' => $g->incident_date?->toDateString(),
            'respondent_description' => $g->respondent_description,
            'accepted_at' => $g->accepted_at?->toIso8601String(),
            'resolved_at' => $g->resolved_at?->toIso8601String(),
            'closed_at' => $g->closed_at?->toIso8601String(),
            'withdrawn_at' => $g->withdrawn_at?->toIso8601String(),
            'intake_reason_code' => $g->intake_reason_code,
            // Handling level and dates only: never the panel or officers.
            'current_stage' => $stage ? [
                'stage_no' => $stage->stage_no,
                'handler' => $this->handler($stage->handler_type->value, $stage->handler_id),
                'status' => $stage->status?->value,
                'received_at' => ($stage->received_at ?? $stage->created_at)?->toIso8601String(),
                'due_at' => $stage->due_at?->toIso8601String(),
                'sla_state' => $this->sla->state($stage)->value,
            ] : null,
            'timeline' => $g->events()->where('visibility', GrievanceEventVisibility::Complainant->value)->get()
                ->map(fn (GrievanceCaseEvent $e) => $this->event($e, false))->values()->all(),
            'letters' => $g->letters()->where('status', GrievanceLetterStatus::Issued->value)->where('visible_to_complainant', true)
                ->orderByDesc('issued_at')->get()->map(fn (GrievanceLetter $l) => [
                    'id' => $l->getKey(), 'letter_type' => $l->letter_type->value, 'reference_number' => $l->reference_number,
                    'subject' => $l->subject, 'letter_date' => $l->letter_date?->toDateString(), 'issued_at' => $l->issued_at?->toIso8601String(),
                ])->values()->all(),
            'evidence' => $g->evidence()->where(fn ($q) => $q->where('submitted_by_complainant', true)->orWhere('submitted_by', $user->getKey()))
                ->with('submitter')->get()->map(fn (GrievanceEvidence $e) => $this->evidence($e))->values()->all(),
            // Only requests addressed to the complainant, with their own answers.
            'information_requests' => $g->informationRequests->where('requested_from_type.value', 'employee')->map(fn ($r) => [
                'id' => $r->getKey(),
                'request_text' => $r->request_text,
                'due_at' => $r->due_at?->toIso8601String(),
                'status' => $r->status?->value,
                'requested_at' => $r->requested_at?->toIso8601String(),
                'responses' => $r->responses->where('responded_by', $user->getKey())->map(fn ($x) => ['response_text' => $x->response_text, 'responded_at' => $x->responded_at?->toIso8601String()])->values()->all(),
            ])->values()->all(),
            'appeals' => $g->appeals->map(fn ($a) => ['id' => $a->getKey(), 'status' => $a->status?->value, 'filed_at' => $a->filed_at?->toIso8601String(), 'reason' => $a->reason])->values()->all(),
        ];
    }

    public function detailsHidden(Grievance $g, bool $canViewDetails): bool
    {
        return ! $canViewDetails || ($g->confidentiality_level === GrievanceConfidentiality::HighlyRestricted && ! $canViewDetails);
    }
}
