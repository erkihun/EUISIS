<?php

declare(strict_types=1);

namespace App\Http\Controllers\Grievances;

use App\Enums\AuditEventType;
use App\Enums\Grievance\GrievanceCaseOfficerRole;
use App\Enums\Grievance\GrievanceConfidentiality;
use App\Enums\Grievance\GrievanceCorrectiveActionStatus;
use App\Enums\Grievance\GrievanceDecisionStatus;
use App\Enums\Grievance\GrievanceDecisionType;
use App\Enums\Grievance\GrievanceDispatchChannel;
use App\Enums\Grievance\GrievanceEvidenceType;
use App\Enums\Grievance\GrievanceHandlerType;
use App\Enums\Grievance\GrievanceHearingMode;
use App\Enums\Grievance\GrievanceInformationTarget;
use App\Enums\Grievance\GrievanceLetterLanguage;
use App\Enums\Grievance\GrievanceLetterType;
use App\Enums\Grievance\GrievanceMovementType;
use App\Enums\Grievance\GrievanceParticipantRole;
use App\Enums\Grievance\GrievancePriority;
use App\Enums\Grievance\GrievanceReasonCodeType;
use App\Enums\Grievance\GrievanceSlaPauseReason;
use App\Enums\Grievance\GrievanceSlaState;
use App\Enums\Grievance\GrievanceTaskStatus;
use App\Enums\Grievance\GrievanceTaskType;
use App\Enums\Grievance\GrievanceVoteType;
use App\Enums\GrievanceStatus;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Grievance;
use App\Models\GrievanceCaseOfficer;
use App\Models\GrievanceCaseRecusal;
use App\Models\GrievanceCaseStage;
use App\Models\GrievanceCategory;
use App\Models\GrievanceCorrectiveAction;
use App\Models\GrievanceReasonCode;
use App\Models\GrievanceSlaPause;
use App\Models\GrievanceTask;
use App\Models\Organization;
use App\Models\OrganizationSeal;
use App\Models\User;
use App\Services\Grievances\GrievanceAppealService;
use App\Services\Grievances\GrievanceAudit;
use App\Services\Grievances\GrievanceCaseAccessService;
use App\Services\Grievances\GrievanceCaseService;
use App\Services\Grievances\GrievanceCommitteeService;
use App\Services\Grievances\GrievanceEscalationService;
use App\Services\Grievances\GrievanceEvidenceService;
use App\Services\Grievances\GrievanceHandlerRegistry;
use App\Services\Grievances\GrievancePresenter;
use App\Services\Grievances\GrievanceRoutingService;
use App\Services\Grievances\GrievanceSettings;
use App\Services\Grievances\GrievanceSlaService;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Staff side of grievance cases: dashboard, work queues, case detail and
 * case actions. Every action re-checks access in the service layer; the `can`
 * map sent to the page only mirrors it.
 */
class GrievanceCaseController extends Controller
{
    /** Holding any of these makes a user part of the grievance staff area. */
    public const STAFF_PERMISSIONS = [
        'grievances.view_assigned', 'grievances.intake_review', 'grievances.assign', 'grievances.oversight_view',
        'grievances.tribunal', 'grievance_decisions.approve', 'grievance_reports.view',
    ];

    public function __construct(
        private readonly GrievanceCaseService $cases,
        private readonly GrievanceCaseAccessService $access,
        private readonly GrievancePresenter $presenter,
        private readonly GrievanceSlaService $sla,
        private readonly GrievanceSettings $settings,
        private readonly OrganizationScopeService $scope,
    ) {}

    // ── Dashboard and queues ────────────────────────────────────────────────

    public function dashboard(Request $request): Response
    {
        $user = $request->user();
        $this->authorizeStaff($user);

        $assigned = $this->access->constrainAssigned(Grievance::query(), $user);
        $open = fn () => (clone $assigned)->with(GrievancePresenter::listRelations());
        $rows = $open()->get();
        $bySla = $rows->groupBy(fn (Grievance $g) => $g->currentStage ? $this->presenter->slaFor($g->currentStage)['state'] : 'no_deadline');

        $intake = $user->can('grievances.intake_review')
            ? Grievance::query()->whereIn('status', ['submitted', 'intake_review'])->tap(fn ($q) => $this->access->applyPermissionScope($q, $user, 'grievances.intake_review'))
                ->tap(fn ($q) => $q->where('submitted_by_user_id', '!=', $user->getKey()))->count()
            : null;

        return Inertia::render('Grievances/Dashboard', [
            'assigned' => [
                'total' => $rows->count(),
                'new' => $rows->filter(fn ($g) => $g->currentStage?->status?->value === 'pending')->count(),
                'due_soon' => ($bySla[GrievanceSlaState::DueSoon->value] ?? collect())->count() + ($bySla[GrievanceSlaState::DueToday->value] ?? collect())->count(),
                'overdue' => ($bySla[GrievanceSlaState::Overdue->value] ?? collect())->count(),
                'awaiting_hearing' => $rows->filter(fn ($g) => $g->currentStage?->status?->value === 'hearing_scheduled')->count(),
                'awaiting_decision' => $rows->filter(fn ($g) => in_array($g->currentStage?->status?->value, ['under_review', 'decision_drafting'], true))->count(),
                'returned' => $rows->filter(fn ($g) => $g->currentStage?->status?->value === 'returned_for_correction')->count(),
                'escalated_in' => $rows->filter(fn ($g) => in_array($g->currentStage?->movement_type?->value, ['timeout_escalation', 'manual_escalation', 'employee_appeal'], true))->count(),
                'pending_approval' => $rows->filter(fn ($g) => $g->currentStage?->status?->value === 'pending_approval')->count(),
            ],
            'queue' => $rows->sortBy(fn ($g) => $g->currentStage?->due_at?->timestamp ?? PHP_INT_MAX)->take(10)
                ->map(fn (Grievance $g) => $this->presenter->listRow($g))->values(),
            'intakePending' => $intake,
            'approvalsPending' => $user->can('grievance_decisions.approve') ? app(GrievanceApprovalController::class)->pendingQuery($user)->count() : null,
            'can' => $this->navCan($user),
        ]);
    }

    public function index(Request $request): Response
    {
        $user = $request->user();
        $this->authorizeStaff($user);
        // `tab` is the sidebar's query key; `scope` the page's own.
        $requested = $request->string('scope')->value() ?: $request->string('tab')->value();
        $scope = in_array($requested, ['assigned', 'authorized', 'intake'], true) ? $requested : 'assigned';

        $query = Grievance::query()->with(GrievancePresenter::listRelations());
        match ($scope) {
            'authorized' => $this->access->constrainAuthorized($query, $user),
            'intake' => $query->whereIn('status', ['submitted', 'intake_review'])->where(fn ($q) => $q->whereNull('submitted_by_user_id')->orWhere('submitted_by_user_id', '!=', $user->getKey()))
                ->tap(fn ($q) => $this->access->applyPermissionScope($q, $user, 'grievances.intake_review')),
            default => $this->access->constrainAssigned($query, $user),
        };
        $query->where('status', '!=', GrievanceStatus::Draft->value);
        $this->applyFilters($query, $request);

        $sort = $request->string('sort')->value();
        match ($sort) {
            'due' => $query->orderByRaw('(select due_at from grievance_case_stages where grievance_case_stages.id = grievances.current_stage_id) is null')
                ->orderBy(GrievanceCaseStage::query()->select('due_at')->whereColumn('grievance_case_stages.id', 'grievances.current_stage_id')),
            'oldest' => $query->orderBy('submitted_at'),
            default => $query->orderByDesc('submitted_at'),
        };

        $page = $query->paginate(20)->withQueryString();
        $page->through(fn (Grievance $g) => $this->presenter->listRow($g, $this->access->canViewDetails($user, $g)));

        return Inertia::render('Grievances/Cases/Index', [
            'grievances' => $page,
            'scope' => $scope,
            'filters' => $request->only(['search', 'status', 'category_id', 'organization_id', 'handler_type', 'sla', 'from', 'to', 'confidentiality', 'sort']),
            'options' => [
                'statuses' => GrievanceStatus::values(),
                'categories' => GrievanceCategory::query()->orderBy('name_en')->get(['id', 'name_en', 'name_am']),
                'organizations' => $this->scope->applyOrganizationScope(Organization::query(), $user, 'id')->where('status', 'active')->orderBy('name_en')->limit(500)->get(['id', 'name_en', 'name_am']),
                'handlerTypes' => [GrievanceHandlerType::Committee->value, GrievanceHandlerType::OrganizationUnit->value, GrievanceHandlerType::ExternalAuthority->value],
                'slaStates' => GrievanceSlaState::values(),
                'confidentiality' => GrievanceConfidentiality::values(),
            ],
            'can' => $this->navCan($user),
        ]);
    }

    public function show(Request $request, Grievance $grievance, GrievanceRoutingService $routing, GrievanceHandlerRegistry $handlers, GrievanceAudit $audit, GrievanceAppealService $appeals, GrievanceEvidenceService $evidence): Response
    {
        $user = $request->user();
        abort_unless($this->access->canView($user, $grievance), 404);

        $details = $this->access->canViewDetails($user, $grievance);
        $internal = $this->access->canSeeInternal($user, $grievance);
        $handles = $this->access->handlesCurrent($user, $grievance);

        // Viewing confidential case content by anyone but the current handler is audited.
        if (! $handles && $details) {
            $audit->record(AuditEventType::GrievanceViewed, $user, $grievance, ['confidentiality' => $grievance->confidentiality_level?->value]);
        }

        $grievance->load([
            'category', 'organization', 'organizationUnit', 'employee', 'respondentEmployee', 'respondentOrganizationUnit',
            'stages.members.employee', 'stages.officers.employee', 'stages.officers.user',
            'currentStage.pauses.requester', 'currentStage.pauses.approver',
        ]);
        $stage = $grievance->currentStage;

        $payload = [
            ...$this->presenter->listRow($grievance, $details),
            'description' => $details ? $grievance->description : null,
            'incident_date' => $grievance->incident_date?->toDateString(),
            'respondent' => $details ? [
                'type' => $grievance->respondent_type,
                'employee' => $grievance->respondentEmployee ? $this->presenter->employee($grievance->respondentEmployee) : null,
                'unit' => $grievance->respondentOrganizationUnit?->only(['id', 'name_en', 'name_am']),
                'description' => $grievance->respondent_description,
            ] : null,
            'organization_unit' => $grievance->organizationUnit?->only(['id', 'name_en', 'name_am']),
            'accepted_at' => $grievance->accepted_at?->toIso8601String(),
            'resolved_at' => $grievance->resolved_at?->toIso8601String(),
            'closed_at' => $grievance->closed_at?->toIso8601String(),
            'closure_reason_code' => $grievance->closure_reason_code,
            'withdrawal_reason_code' => $grievance->withdrawal_reason_code,
            'withdrawal_reason' => $internal ? $grievance->withdrawal_reason : null,
            'intake_reason_code' => $grievance->intake_reason_code,
            'intake_notes' => $internal ? $grievance->intake_notes : null,
            'legal_hold' => $grievance->legal_hold,
            'legal_hold_reason' => $internal ? $grievance->legal_hold_reason : null,
            'retention_until' => $grievance->retention_until?->toDateString(),
            'archived_at' => $grievance->archived_at?->toIso8601String(),
            'root_cause_category' => $grievance->root_cause_category,
            'systemic_issue_flag' => $grievance->systemic_issue_flag,
            'corrective_action_required' => $grievance->corrective_action_required,
            'reopened_count' => $grievance->reopened_count,
            'current_stage' => $stage ? $this->presenter->stage($stage, $internal) : null,
            'stages' => $grievance->stages->map(fn ($s) => $this->presenter->stage($s, $internal))->values(),
            'pauses' => $stage && $internal ? $stage->pauses->map(fn (GrievanceSlaPause $p) => [
                'id' => $p->getKey(), 'reason' => $p->pause_reason->value, 'status' => $p->status->value, 'notes' => $p->notes,
                'requested_by' => $p->requester?->name, 'approved_by' => $p->approver?->name,
                'requested_at' => $p->requested_at?->toIso8601String(), 'started_at' => $p->started_at?->toIso8601String(), 'ended_at' => $p->ended_at?->toIso8601String(),
                'due_at_before' => $p->due_at_before?->toIso8601String(), 'due_at_after' => $p->due_at_after?->toIso8601String(), 'paused_days' => $p->paused_days,
            ])->values() : [],
        ];

        $tabs = [];
        if ($details) {
            $tabs['timeline'] = $grievance->events()->with('actor:id,name')->get()
                ->filter(fn ($e) => $internal || $e->visibility?->value === 'complainant')
                ->map(fn ($e) => $this->presenter->event($e, $internal))->values();
            $tabs['evidence'] = $grievance->evidence()->with('submitter:id,name')->latest('submitted_at')->get()
                ->filter(fn ($e) => $this->access->canViewEvidence($user, $e))->map(fn ($e) => $this->presenter->evidence($e))->values();
            $tabs['information_requests'] = $grievance->informationRequests()->with(['responses.responder:id,name', 'requester:id,name'])->latest('requested_at')->get()->map(fn ($r) => [
                'id' => $r->getKey(), 'requested_from_type' => $r->requested_from_type->value, 'requested_from_id' => $r->requested_from_id,
                'requested_from_name' => $r->requested_from_name, 'request_text' => $r->request_text, 'due_at' => $r->due_at?->toIso8601String(),
                'status' => $r->status->value, 'pauses_sla' => $r->pauses_sla, 'requested_by' => $r->requester?->name,
                'requested_at' => $r->requested_at?->toIso8601String(), 'responded_at' => $r->responded_at?->toIso8601String(),
                'responses' => $r->responses->map(fn ($x) => ['id' => $x->getKey(), 'response_text' => $x->response_text, 'responded_by' => $x->responder?->name, 'responded_at' => $x->responded_at?->toIso8601String()])->values(),
            ])->values();
            $tabs['hearings'] = $grievance->hearings()->with(['participants.employee', 'chairperson'])->orderByDesc('scheduled_at')->get()->map(fn ($h) => [
                'id' => $h->getKey(), 'stage_id' => $h->case_stage_id, 'scheduled_at' => $h->scheduled_at?->toIso8601String(), 'duration_minutes' => $h->duration_minutes,
                'location' => $h->location, 'mode' => $h->mode->value, 'meeting_link' => $internal ? $h->meeting_link : null, 'status' => $h->status->value,
                'agenda' => $h->agenda, 'notes' => $internal ? $h->notes : null, 'cancellation_reason' => $h->cancellation_reason, 'held_at' => $h->held_at?->toIso8601String(),
                'chairperson' => $h->chairperson ? $this->presenter->employee($h->chairperson) : null,
                'participants' => $h->participants->map(fn ($p) => [
                    'id' => $p->getKey(), 'role' => $p->role->value, 'employee' => $p->employee ? $this->presenter->employee($p->employee) : null,
                    'name' => $p->name, 'affiliation' => $p->affiliation, 'contact' => $internal ? $p->contact : null, 'attendance' => $p->attendance,
                    'notice_sent_at' => $p->notice_sent_at?->toIso8601String(), 'acknowledged_at' => $p->acknowledged_at?->toIso8601String(),
                ])->values(),
            ])->values();
            $tabs['minutes'] = $internal ? $grievance->minutes()->with(['preparer:id,name', 'confirmer:id,name'])->orderByDesc('meeting_date')->orderByDesc('version_no')->get()->map(fn ($m) => [
                'id' => $m->getKey(), 'hearing_id' => $m->hearing_id, 'stage_id' => $m->case_stage_id, 'meeting_date' => $m->meeting_date?->toDateString(), 'summary' => $m->summary,
                'discussion' => $m->discussion, 'resolutions' => $m->resolutions, 'attendees' => $m->attendees, 'status' => $m->status->value,
                'version_no' => $m->version_no, 'supersedes_minutes_id' => $m->supersedes_minutes_id, 'amendment_reason' => $m->amendment_reason,
                'prepared_by' => $m->preparer?->name, 'confirmed_by' => $m->confirmer?->name, 'confirmed_at' => $m->confirmed_at?->toIso8601String(),
            ])->values() : [];
            $tabs['decisions'] = $user->can('grievance_decisions.view') || $this->access->isApproverOf($user, $grievance)
                ? $grievance->decisions()->with(['approvals.actor:id,name', 'votes.employee', 'preparer:id,name', 'approverPosition'])->orderByDesc('created_at')->get()
                    ->map(fn ($d) => $this->presenter->decision($d, $internal))->values()
                : [];
            $tabs['letters'] = $user->can('grievance_correspondence.view') || $this->access->canPrepareLetters($user, $grievance)
                ? $grievance->letters()->with(['recipients', 'attachments', 'dispatches', 'signatoryEmployee'])->orderByDesc('created_at')->get()
                    ->map(fn ($l) => $this->presenter->letter($l, $internal))->values()
                : [];
            $tabs['appeals'] = $grievance->appeals()->with(['fromStage', 'toStage'])->get()->map(fn ($a) => [
                'id' => $a->getKey(), 'status' => $a->status->value, 'reason' => $a->reason, 'filed_at' => $a->filed_at?->toIso8601String(),
                'deadline_at' => $a->deadline_at?->toIso8601String(), 'from_stage_no' => $a->fromStage?->stage_no, 'to_stage_no' => $a->toStage?->stage_no,
            ])->values();
            $tabs['notes'] = $internal ? $grievance->notes()->with('author:id,name')->latest()->get()->map(fn ($n) => ['id' => $n->getKey(), 'body' => $n->body, 'author' => $n->author?->name, 'created_at' => $n->created_at?->toIso8601String()])->values() : [];
            $tabs['tasks'] = $internal ? $grievance->tasks()->with('assignee:id,name')->orderBy('status')->orderBy('due_at')->get()->map(fn ($t) => [
                'id' => $t->getKey(), 'task_type' => $t->task_type->value, 'title' => $t->title, 'assignee' => $t->assignee?->name, 'assigned_to_user_id' => $t->assigned_to_user_id,
                'due_at' => $t->due_at?->toIso8601String(), 'status' => $t->status->value, 'completed_at' => $t->completed_at?->toIso8601String(),
            ])->values() : [];
            $tabs['recusals'] = $internal ? $grievance->recusals()->with(['employee', 'replacementEmployee', 'decidedBy:id,name'])->latest('declared_at')->get()->map(fn (GrievanceCaseRecusal $r) => [
                'id' => $r->getKey(), 'stage_id' => $r->case_stage_id, 'employee' => $r->employee ? $this->presenter->employee($r->employee) : null, 'reason' => $r->reason,
                'status' => $r->status->value, 'declared_at' => $r->declared_at?->toIso8601String(), 'decided_by' => $r->decidedBy?->name, 'decided_at' => $r->decided_at?->toIso8601String(),
                'decision_notes' => $r->decision_notes, 'replacement' => $r->replacementEmployee ? $this->presenter->employee($r->replacementEmployee) : null,
            ])->values() : [];
            $tabs['corrective_actions'] = $grievance->correctiveActions()->with(['responsibleOrganization:id,name_en,name_am', 'responsibleUnit:id,name_en,name_am'])->get()->map(fn ($a) => [
                'id' => $a->getKey(), 'description' => $a->description, 'decision_id' => $a->decision_id, 'due_date' => $a->due_date?->toDateString(), 'status' => $a->status->value,
                'responsible_organization' => $a->responsibleOrganization?->only(['id', 'name_en', 'name_am']), 'responsible_unit' => $a->responsibleUnit?->only(['id', 'name_en', 'name_am']),
                'completion_notes' => $a->completion_notes, 'completed_at' => $a->completed_at?->toIso8601String(),
            ])->values();
            $tabs['referrals'] = $grievance->disciplinaryReferrals()->with(['referredToOrganization:id,name_en,name_am', 'referredToUnit:id,name_en,name_am'])->get()->map(fn ($r) => [
                'id' => $r->getKey(), 'reason' => $r->reason, 'status' => $r->status->value, 'referred_at' => $r->referred_at?->toIso8601String(),
                'organization' => $r->referredToOrganization?->only(['id', 'name_en', 'name_am']), 'unit' => $r->referredToUnit?->only(['id', 'name_en', 'name_am']),
                'disciplinary_case_reference' => $r->disciplinary_case_reference,
            ])->values();
            $tabs['amendments'] = $internal ? $grievance->amendments()->with('amender:id,name')->latest('amended_at')->get()->map(fn ($a) => ['id' => $a->getKey(), 'changes' => $a->changes, 'reason' => $a->reason, 'by' => $a->amender?->name, 'at' => $a->amended_at?->toIso8601String()])->values() : [];
        }
        if ($user->can('grievances.view_audit') && $details) {
            // Everything recorded against this case or one of its records
            // (id match on auditable_id; no JSON LIKE, which PostgreSQL rejects).
            $relatedIds = collect([$grievance->getKey()])
                ->merge($grievance->decisions()->pluck('id'))->merge($grievance->letters()->pluck('id'))
                ->merge($grievance->evidence()->pluck('id'))->merge($grievance->hearings()->pluck('id'))
                ->merge($grievance->minutes()->pluck('id'))->merge($grievance->informationRequests()->pluck('id'))
                ->merge($grievance->recusals()->pluck('id'))->merge($grievance->appeals()->pluck('id'))
                ->merge($grievance->correctiveActions()->pluck('id'))->merge($grievance->disciplinaryReferrals()->pluck('id'))
                ->merge(GrievanceSlaPause::query()->where('grievance_id', $grievance->getKey())->pluck('id'))
                ->map(fn ($id) => (string) $id)->all();
            $logs = AuditLog::query()
                ->whereIn('auditable_id', $relatedIds)
                ->where('event_type', 'like', 'grievance.%')
                ->latest('created_at')->limit(300)->get();
            $actors = User::query()->whereIn('id', $logs->pluck('actor_user_id')->filter()->unique())->pluck('name', 'id');
            $tabs['audit'] = $logs->map(fn ($a) => [
                'id' => $a->getKey(), 'event_type' => $a->event_type?->value ?? $a->event_type, 'actor' => $actors[$a->actor_user_id] ?? null,
                'new_values' => $a->new_values, 'old_values' => $a->old_values, 'reason' => $a->reason,
                'created_at' => $a->created_at?->toIso8601String(), 'request_ip' => $a->request_ip,
            ])->values();
        }

        $lead = $this->access->isStageLead($user, $grievance);
        $options = [];
        if ($handles || $this->access->canIntake($user, $grievance)) {
            $options = $this->actionOptions($grievance, $user, $routing, $handlers, $evidence);
        }

        return Inertia::render('Grievances/Cases/Show', [
            'grievance' => $payload,
            'tabs' => $tabs,
            'options' => $options,
            'viewer' => [
                'details' => $details,
                'internal' => $internal,
                'handles' => $handles,
                'lead' => $lead,
                'panel_role' => $this->access->panelRole($user, $stage)?->value,
                'pending_recusal' => $this->access->hasPendingRecusal($user, $grievance),
                'oversight_only' => ! $handles && ! $this->access->handledAnyStage($user, $grievance) && $this->access->isOversight($user, $grievance),
            ],
            'appeal' => $appeals->eligibility($grievance)['allowed'] ? ['open' => true, 'deadline' => $grievance->appeal_deadline_at?->toIso8601String()] : ['open' => false, 'deadline' => $grievance->appeal_deadline_at?->toIso8601String()],
            'can' => $this->caseCan($user, $grievance, $lead, $handles),
        ]);
    }

    // ── Intake ───────────────────────────────────────────────────────────────

    public function intake(Request $request, Grievance $grievance): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:accept,return,reject'],
            'reason_code' => ['required_unless:action,accept', 'nullable', 'string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:4000'],
        ]);
        $user = $request->user();
        match ($data['action']) {
            'accept' => $this->cases->intakeAccept($grievance, $user, $data['notes'] ?? null),
            'return' => $this->cases->intakeReturn($grievance, $user, (string) $data['reason_code'], $data['notes'] ?? null),
            'reject' => $this->cases->intakeReject($grievance, $user, (string) $data['reason_code'], $data['notes'] ?? null),
        };

        return back()->with('flash', ['message' => __('grievances.flash.intake_'.$data['action']), 'type' => 'success']);
    }

    // ── Stage work ───────────────────────────────────────────────────────────

    public function receive(Request $request, Grievance $grievance): RedirectResponse
    {
        $this->cases->receive($grievance, $request->user());

        return back()->with('flash', ['message' => __('grievances.flash.received'), 'type' => 'success']);
    }

    public function startReview(Request $request, Grievance $grievance): RedirectResponse
    {
        $this->cases->startReview($grievance, $request->user());

        return back()->with('flash', ['message' => __('grievances.flash.review_started'), 'type' => 'success']);
    }

    public function classify(Request $request, Grievance $grievance): RedirectResponse
    {
        $data = $request->validate([
            'confidentiality_level' => ['sometimes', Rule::in(GrievanceConfidentiality::values())],
            'priority' => ['sometimes', Rule::in(GrievancePriority::values())],
            'respondent_type' => ['sometimes', 'nullable', 'in:employee,organization_unit,decision,other'],
            'respondent_employee_id' => ['sometimes', 'nullable', 'uuid', 'exists:employees,id'],
            'respondent_organization_unit_id' => ['sometimes', 'nullable', 'uuid', 'exists:organization_units,id'],
            'respondent_description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'root_cause_category' => ['sometimes', 'nullable', 'string', 'max:60'],
            'systemic_issue_flag' => ['sometimes', 'boolean'],
            'corrective_action_required' => ['sometimes', 'boolean'],
        ]);
        $this->cases->classify($grievance, $request->user(), $data);

        return back()->with('flash', ['message' => __('grievances.flash.saved'), 'type' => 'success']);
    }

    public function move(Request $request, Grievance $grievance, GrievanceEscalationService $escalation): RedirectResponse
    {
        $data = $request->validate([
            'movement' => ['required', 'in:manual_escalation,reassigned,referred,returned'],
            'route_id' => ['required', 'uuid'],
            'reason' => ['required_unless:movement,reassigned', 'nullable', 'string', 'max:4000'],
            'reason_code' => ['required_if:movement,reassigned', 'nullable', 'string', 'max:60'],
        ]);
        $user = $request->user();
        if ($data['movement'] === 'reassigned') {
            $this->cases->assertReasonCode(GrievanceReasonCodeType::Reassignment, (string) $data['reason_code']);
        }
        match ($data['movement']) {
            'manual_escalation' => $escalation->manualEscalate($grievance, $user, (string) $data['reason'], $data['route_id']),
            'reassigned' => $escalation->reassign($grievance, $user, (string) $data['reason_code'], $data['reason'] ?? null, $data['route_id']),
            'referred' => $escalation->refer($grievance, $user, (string) $data['reason'], $data['route_id']),
            'returned' => $escalation->returnToHandler($grievance, $user, (string) $data['reason'], $data['route_id']),
        };

        return back()->with('flash', ['message' => __('grievances.flash.moved'), 'type' => 'success']);
    }

    public function assignOfficer(Request $request, Grievance $grievance): RedirectResponse
    {
        $data = $request->validate(['user_id' => ['required', 'integer', 'exists:users,id'], 'role' => ['required', Rule::in(GrievanceCaseOfficerRole::values())]]);
        $this->cases->assignOfficer($grievance, $request->user(), User::query()->findOrFail($data['user_id']), GrievanceCaseOfficerRole::from($data['role']));

        return back()->with('flash', ['message' => __('grievances.flash.officer_assigned'), 'type' => 'success']);
    }

    public function releaseOfficer(Request $request, Grievance $grievance, GrievanceCaseOfficer $officer): RedirectResponse
    {
        $this->cases->releaseOfficer($grievance, $request->user(), $officer);

        return back()->with('flash', ['message' => __('grievances.flash.saved'), 'type' => 'success']);
    }

    public function storeNote(Request $request, Grievance $grievance): RedirectResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:10000']]);
        $this->cases->addNote($grievance, $request->user(), $data['body']);

        return back()->with('flash', ['message' => __('grievances.flash.saved'), 'type' => 'success']);
    }

    public function storeTask(Request $request, Grievance $grievance): RedirectResponse
    {
        $data = $request->validate([
            'task_type' => ['required', Rule::in(GrievanceTaskType::values())],
            'title' => ['required', 'string', 'max:255'],
            'assigned_to_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'due_at' => ['nullable', 'date'],
        ]);
        $this->cases->addTask($grievance, $request->user(), $data);

        return back()->with('flash', ['message' => __('grievances.flash.saved'), 'type' => 'success']);
    }

    public function updateTask(Request $request, Grievance $grievance, GrievanceTask $task): RedirectResponse
    {
        abort_unless($task->grievance_id === $grievance->getKey(), 404);
        $data = $request->validate(['status' => ['required', Rule::in(GrievanceTaskStatus::values())]]);
        $this->cases->completeTask($task, $request->user(), GrievanceTaskStatus::from($data['status']));

        return back()->with('flash', ['message' => __('grievances.flash.saved'), 'type' => 'success']);
    }

    // ── SLA pauses ───────────────────────────────────────────────────────────

    public function requestPause(Request $request, Grievance $grievance): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', Rule::in(GrievanceSlaPauseReason::values())], 'notes' => ['nullable', 'string', 'max:2000']]);
        $user = $request->user();
        $this->access->authorize($this->access->canReview($user, $grievance));
        $this->sla->requestPause($this->access->currentStage($grievance), GrievanceSlaPauseReason::from($data['reason']), $data['notes'] ?? null, $user);

        return back()->with('flash', ['message' => __('grievances.flash.pause_recorded'), 'type' => 'success']);
    }

    public function decidePause(Request $request, Grievance $grievance, GrievanceSlaPause $pause): RedirectResponse
    {
        abort_unless($pause->grievance_id === $grievance->getKey(), 404);
        $data = $request->validate(['action' => ['required', 'in:approve,reject,resume'], 'notes' => ['nullable', 'string', 'max:2000']]);
        $user = $request->user();
        $this->access->authorize($user->can('grievances.sla_pause') && ($this->access->canReview($user, $grievance) || $this->access->isStageLead($user, $grievance))
            && ($data['action'] === 'resume' || (int) $pause->requested_by !== (int) $user->getKey()));

        match ($data['action']) {
            'approve' => $this->sla->approvePause($pause, $user),
            'reject' => $this->sla->rejectPause($pause, $user, $data['notes'] ?? null),
            'resume' => $this->sla->resume($pause, $user),
        };

        return back()->with('flash', ['message' => __('grievances.flash.saved'), 'type' => 'success']);
    }

    // ── Recusal ──────────────────────────────────────────────────────────────

    public function declareRecusal(Request $request, Grievance $grievance, GrievanceCommitteeService $committees): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:4000']]);
        $committees->declareRecusal($grievance, $request->user(), $data['reason']);

        return back()->with('flash', ['message' => __('grievances.flash.recusal_declared'), 'type' => 'success']);
    }

    public function decideRecusal(Request $request, Grievance $grievance, GrievanceCaseRecusal $recusal, GrievanceCommitteeService $committees): RedirectResponse
    {
        abort_unless($recusal->grievance_id === $grievance->getKey(), 404);
        $data = $request->validate([
            'approve' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'replacement_employee_id' => ['nullable', 'uuid', 'exists:employees,id'],
        ]);
        $committees->decideRecusal($recusal, $request->user(), (bool) $data['approve'], $data['notes'] ?? null, $data['replacement_employee_id'] ?? null);

        return back()->with('flash', ['message' => __('grievances.flash.saved'), 'type' => 'success']);
    }

    // ── Withdrawal, closure, retention ───────────────────────────────────────

    public function decideWithdrawal(Request $request, Grievance $grievance): RedirectResponse
    {
        $data = $request->validate(['approve' => ['required', 'boolean'], 'notes' => ['nullable', 'string', 'max:2000']]);
        $this->cases->decideWithdrawal($grievance, $request->user(), (bool) $data['approve'], $data['notes'] ?? null);

        return back()->with('flash', ['message' => __('grievances.flash.saved'), 'type' => 'success']);
    }

    public function close(Request $request, Grievance $grievance): RedirectResponse
    {
        $data = $request->validate(['reason_code' => ['required', 'string', 'max:60'], 'notes' => ['nullable', 'string', 'max:4000']]);
        $this->cases->close($grievance, $request->user(), $data['reason_code'], $data['notes'] ?? null);

        return back()->with('flash', ['message' => __('grievances.flash.closed'), 'type' => 'success']);
    }

    public function reopen(Request $request, Grievance $grievance): RedirectResponse
    {
        $data = $request->validate(['reason_code' => ['required', 'string', 'max:60'], 'reason' => ['required', 'string', 'max:4000']]);
        $this->cases->reopen($grievance, $request->user(), $data['reason_code'], $data['reason']);

        return back()->with('flash', ['message' => __('grievances.flash.reopened'), 'type' => 'success']);
    }

    public function archive(Request $request, Grievance $grievance): RedirectResponse
    {
        $this->cases->archive($grievance, $request->user());

        return back()->with('flash', ['message' => __('grievances.flash.archived'), 'type' => 'success']);
    }

    public function legalHold(Request $request, Grievance $grievance): RedirectResponse
    {
        $data = $request->validate(['hold' => ['required', 'boolean'], 'reason' => ['required', 'string', 'max:2000']]);
        $this->cases->setLegalHold($grievance, $request->user(), (bool) $data['hold'], $data['reason']);

        return back()->with('flash', ['message' => __('grievances.flash.saved'), 'type' => 'success']);
    }

    // ── Outcomes ─────────────────────────────────────────────────────────────

    public function storeCorrectiveAction(Request $request, Grievance $grievance): RedirectResponse
    {
        $data = $request->validate([
            'description' => ['required', 'string', 'max:4000'],
            'decision_id' => ['nullable', 'uuid', Rule::exists('grievance_decisions', 'id')->where('grievance_id', $grievance->getKey())],
            'responsible_organization_id' => ['nullable', 'uuid', 'exists:organizations,id'],
            'responsible_organization_unit_id' => ['nullable', 'uuid', 'exists:organization_units,id'],
            'due_date' => ['nullable', 'date'],
        ]);
        $this->cases->recordCorrectiveAction($grievance, $request->user(), $data);

        return back()->with('flash', ['message' => __('grievances.flash.saved'), 'type' => 'success']);
    }

    public function updateCorrectiveAction(Request $request, Grievance $grievance, GrievanceCorrectiveAction $action): RedirectResponse
    {
        abort_unless($action->grievance_id === $grievance->getKey(), 404);
        $data = $request->validate([
            'status' => ['required', Rule::in(GrievanceCorrectiveActionStatus::values())],
            'completion_notes' => ['nullable', 'string', 'max:4000'],
            'completion_evidence_id' => ['nullable', 'uuid', Rule::exists('grievance_evidence', 'id')->where('grievance_id', $grievance->getKey())],
        ]);
        $this->cases->updateCorrectiveAction($action, $request->user(), $data);

        return back()->with('flash', ['message' => __('grievances.flash.saved'), 'type' => 'success']);
    }

    public function storeReferral(Request $request, Grievance $grievance): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:4000'],
            'decision_id' => ['nullable', 'uuid', Rule::exists('grievance_decisions', 'id')->where('grievance_id', $grievance->getKey())],
            'referred_to_organization_id' => ['nullable', 'uuid', 'exists:organizations,id'],
            'referred_to_organization_unit_id' => ['nullable', 'uuid', 'exists:organization_units,id'],
        ]);
        $this->cases->referToDisciplinary($grievance, $request->user(), $data);

        return back()->with('flash', ['message' => __('grievances.flash.saved'), 'type' => 'success']);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    public function authorizeStaff(User $user): void
    {
        abort_unless($user->isSuperAdmin() || collect(self::STAFF_PERMISSIONS)->contains(fn (string $p) => $user->can($p)), 403);
    }

    /** @return array<string, bool> */
    public function navCan(User $user): array
    {
        return [
            'intake' => $user->can('grievances.intake_review'),
            'authorized' => $user->can('grievances.oversight_view') || $user->can('grievances.view_assigned') || $user->can('grievances.assign') || $user->isSuperAdmin(),
            'approvals' => $user->can('grievance_decisions.approve'),
            'reports' => $user->can('grievance_reports.view'),
            'export' => $user->can('grievance_reports.export'),
            'correspondence' => $user->can('grievance_correspondence.view'),
            'committees' => $user->can('grievance_committees.view'),
            'routes' => $user->can('grievance_routes.view'),
            'sla' => $user->can('grievance_sla.view'),
            'configuration' => $user->can('grievance_settings.view'),
        ];
    }

    /** @param  Builder<Grievance>  $query */
    private function applyFilters(Builder $query, Request $request): void
    {
        if ($request->filled('search')) {
            // Search by identifiers only; confidential narrative is never full-text searched.
            $term = trim((string) $request->string('search'));
            $query->where(fn ($q) => $q->where('reference_number', 'like', "%{$term}%")
                ->orWhereHas('employee', fn ($e) => $e->where('employee_number', 'like', "%{$term}%")));
        }
        foreach (['status', 'category_id', 'organization_id', 'confidentiality_level' => 'confidentiality'] as $column => $param) {
            $column = is_int($column) ? $param : $column;
            if ($request->filled($param)) {
                $query->where($column, (string) $request->string($param));
            }
        }
        if ($request->filled('handler_type')) {
            $query->where('current_handler_type', (string) $request->string('handler_type'));
        }
        if ($request->filled('from')) {
            $query->whereDate('submitted_at', '>=', (string) $request->string('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('submitted_at', '<=', (string) $request->string('to'));
        }
        if ($request->filled('sla')) {
            $state = (string) $request->string('sla');
            $query->whereHas('currentStage', function ($s) use ($state): void {
                $s->whereIn('status', GrievanceCaseStage::OPEN_STATUSES)->where('is_current', true);
                match ($state) {
                    'overdue' => $s->where('due_at', '<', now()),
                    'due_today' => $s->whereBetween('due_at', [now(), now()->endOfDay()]),
                    'due_soon' => $s->whereBetween('due_at', [now(), now()->addDays(max(1, $this->settings->dueSoonDays()) + 2)->endOfDay()]),
                    'on_track' => $s->where('due_at', '>', now()->endOfDay()),
                    'no_deadline' => $s->whereNull('due_at'),
                    default => null,
                };
            });
        }
    }

    /** @return array<string, mixed> */
    private function actionOptions(Grievance $grievance, User $user, GrievanceRoutingService $routing, GrievanceHandlerRegistry $handlers, GrievanceEvidenceService $evidence): array
    {
        $stage = $this->access->currentStage($grievance);
        $routes = [];
        if ($stage !== null && $stage->isOpen()) {
            foreach (['manual_escalation', 'reassigned', 'referred', 'returned'] as $movement) {
                $routes[$movement] = collect($routing->nextHandlers($stage, GrievanceMovementType::from($movement), $grievance->category_id))
                    ->map(fn ($o) => ['route_id' => $o['route']->getKey(), 'handler' => $this->presenter->handler($o['type']->value, $o['id'])])->values();
            }
        }

        $unitOfficers = [];
        if ($stage?->handler_type === GrievanceHandlerType::OrganizationUnit && $this->access->canAssignOfficers($user, $grievance)) {
            $unitOfficers = collect($handlers->unitUsersWithPermission((string) $stage->handler_id, 'grievances.view_assigned'))
                ->reject(fn (User $u) => $this->access->isConflicted($u, $grievance))
                ->map(fn (User $u) => ['id' => $u->getKey(), 'name' => $u->name])->values();
        }

        $reason = fn (GrievanceReasonCodeType $type) => GrievanceReasonCode::query()->where('type', $type->value)->where('is_active', true)->orderBy('sort_order')->get(['code', 'name_en', 'name_am']);

        return [
            'routes' => $routes,
            'unit_officers' => $unitOfficers,
            'reason_codes' => [
                'intake_return' => $reason(GrievanceReasonCodeType::IntakeReturn),
                'intake_rejection' => $this->settings->allowRejectionAtIntake() ? $reason(GrievanceReasonCodeType::IntakeRejection) : [],
                'closure' => $reason(GrievanceReasonCodeType::Closure),
                'reopen' => $reason(GrievanceReasonCodeType::Reopen),
                'reassignment' => $reason(GrievanceReasonCodeType::Reassignment),
            ],
            'decision_types' => GrievanceDecisionType::values(),
            'vote_types' => GrievanceVoteType::values(),
            'voting_enabled' => $this->settings->votingEnabled(),
            'dissent_enabled' => $this->settings->dissentEnabled(),
            'evidence_types' => GrievanceEvidenceType::values(),
            'evidence_extensions' => $this->settings->evidenceAllowedExtensions(),
            'max_file_kb' => $this->settings->evidenceMaxSizeKb(),
            'information_targets' => GrievanceInformationTarget::values(),
            'hearing_modes' => GrievanceHearingMode::values(),
            'participant_roles' => GrievanceParticipantRole::values(),
            'pause_reasons' => GrievanceSlaPauseReason::values(),
            'task_types' => GrievanceTaskType::values(),
            'letter_types' => GrievanceLetterType::values(),
            'letter_languages' => GrievanceLetterLanguage::values(),
            'dispatch_channels' => GrievanceDispatchChannel::values(),
            'seals' => $user->can('grievance_correspondence.apply_seal')
                ? OrganizationSeal::query()->where('status', 'active')->whereNotNull('approved_at')->get(['id', 'name', 'organization_id'])
                : [],
            'allow_intake_rejection' => $this->settings->allowRejectionAtIntake(),
            'decision_statuses' => GrievanceDecisionStatus::values(),
        ];
    }

    /** @return array<string, bool> */
    private function caseCan(User $user, Grievance $grievance, bool $lead, bool $handles): array
    {
        $a = $this->access;
        $status = $grievance->status;
        $stage = $a->currentStage($grievance);

        return [
            'intake' => $a->canIntake($user, $grievance),
            'receive' => $handles && $stage?->status?->value === 'pending',
            'start_review' => $a->canReview($user, $grievance) && in_array($stage?->status?->value, ['pending', 'received'], true),
            'review' => $a->canReview($user, $grievance),
            'classify' => $a->canLead($user, $grievance, 'grievances.review') || $a->canIntake($user, $grievance),
            'escalate' => $a->canHandle($user, $grievance, 'grievances.escalate'),
            'reassign' => $a->canHandle($user, $grievance, 'grievances.reassign'),
            'refer' => $a->canLead($user, $grievance, 'grievances.escalate'),
            'assign_officers' => $a->canAssignOfficers($user, $grievance),
            'request_information' => $a->canHandle($user, $grievance, 'grievances.request_information'),
            'pause' => $a->canReview($user, $grievance) && $stage?->due_at !== null,
            'decide_pause' => $user->can('grievances.sla_pause') && ($a->canReview($user, $grievance) || $lead),
            'declare_recusal' => $a->panelSeat($user, $stage) !== null && ! $a->hasPendingRecusal($user, $grievance),
            'decide_recusal' => $user->can('grievances.decide_recusal') && ($lead || $this->scope->canExercisePermission($user, 'grievances.decide_recusal', $grievance->organization_id)),
            'manage_hearings' => $a->canManageHearings($user, $grievance),
            'confirm_minutes' => $a->canLead($user, $grievance, 'grievance_hearings.manage'),
            'draft_decision' => $a->canDraftDecision($user, $grievance),
            'review_decision' => $a->canLead($user, $grievance, 'grievance_decisions.review'),
            'submit_decision' => $a->canLead($user, $grievance, 'grievance_decisions.submit_for_approval'),
            'finalize_decision' => $a->canLead($user, $grievance, 'grievance_decisions.finalize'),
            'vote' => $a->panelSeat($user, $stage) !== null && $user->can('grievances.review') && ($this->settings->votingEnabled() || $this->settings->dissentEnabled()),
            'prepare_letters' => $a->canPrepareLetters($user, $grievance) || $a->canLead($user, $grievance, 'grievance_correspondence.create'),
            'sign_letters' => $user->can('grievance_correspondence.sign'),
            'seal_letters' => $user->can('grievance_correspondence.apply_seal'),
            'issue_letters' => $user->can('grievance_correspondence.issue') && ($lead || $a->canHandle($user, $grievance, 'grievance_correspondence.issue')),
            'upload_evidence' => $a->canReview($user, $grievance),
            'notes' => $a->canReview($user, $grievance) || $lead,
            'decide_withdrawal' => $status === GrievanceStatus::WithdrawRequested && ($lead || $a->canHandle($user, $grievance, 'grievances.close')),
            'close' => in_array($status, [GrievanceStatus::DecisionIssued, GrievanceStatus::ReferredExternal], true) && ($lead || $a->canHandle($user, $grievance, 'grievances.close')),
            'reopen' => in_array($status, [GrievanceStatus::Closed, GrievanceStatus::DecisionIssued], true) && $user->can('grievances.reopen') && ($a->isOversight($user, $grievance) || $a->handledAnyStage($user, $grievance)),
            'archive' => $user->can('grievances.archive') && $a->isOversight($user, $grievance),
            'outcomes' => $a->canReview($user, $grievance) || $a->canLead($user, $grievance, 'grievances.review'),
        ];
    }
}
