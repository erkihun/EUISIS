<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AssessmentCycle;
use App\Models\AssessmentRecord;
use App\Models\AssessmentResponse;
use App\Models\User;
use App\Services\Assessment\Execution\AssessmentAssignmentService;
use App\Services\Assessment\Execution\AssessmentFormDefinition;
use App\Services\Assessment\Execution\AssessmentReviewService;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Reviewer workspace and controlled evaluator management
 * (docs/assessment-evaluator-workflow.md#review). Every list and action is
 * limited to the reviewer's organization scope; a reviewer never edits an
 * evaluator's answers.
 */
class AssessmentReviewController extends Controller
{
    public const STATUSES = ['submitted', 'assigned', 'pending_assignment', 'reviewed', 'acknowledged'];

    public function __construct(
        private readonly AssessmentReviewService $reviews,
        private readonly AssessmentAssignmentService $assignments,
        private readonly AssessmentFormDefinition $definitions,
        private readonly OrganizationScopeService $scope,
    ) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->canAny(['assessments.review', 'assessment_assignments.view']), 403);
        $filters = array_filter($request->validate(['status' => ['nullable', Rule::in(self::STATUSES)], 'cycle' => ['nullable', 'uuid'], 'search' => ['nullable', 'string', 'max:100']]));
        $filters['status'] ??= 'submitted';
        $query = $this->scope->applyOrganizationScope(AssessmentRecord::query(), $user)
            ->where('status', $filters['status'])
            ->when($filters['cycle'] ?? null, fn ($q, $v) => $q->where('assessment_cycle_id', $v))
            ->when($filters['search'] ?? null, function ($q, $v): void {
                $like = '%'.mb_strtolower(trim($v)).'%';
                $q->whereIn('employee_id', \App\Models\Employee::query()->where(fn ($w) => $w->whereRaw('LOWER(employee_number) LIKE ?', [$like])->orWhereRaw('LOWER(full_name) LIKE ?', [$like]))->select('id'));
            })
            ->with(['version:id,name_en,name_am,version_no', 'cycle:id,code,name_en,name_am'])
            ->withCount(['responses as evaluators_active' => fn ($q) => $q->whereIn('status', AssessmentResponse::ACTIVE)])
            ->withMax(['responses as last_submitted_at' => fn ($q) => $q->where('status', 'submitted')], 'submitted_at')
            ->orderByDesc('updated_at')->orderBy('id');
        $rows = $query->paginate(25)->withQueryString()->through(fn (AssessmentRecord $r): array => [
            'id' => $r->id, 'status' => $r->status, 'employee' => collect($r->employee_snapshot)->only(['name', 'name_en', 'number', 'unit', 'unit_am', 'position', 'position_am'])->all(),
            'form' => $r->version?->only(['name_en', 'name_am', 'version_no']), 'cycle' => $r->cycle?->only(['code', 'name_en', 'name_am']),
            'evaluators' => (int) $r->evaluators_active,
            'raw' => collect($r->score_breakdown['components'] ?? [])->count() === 1 ? ($r->score_breakdown['components'][0]['raw_average'] ?? null) : null,
            'percentage' => $r->percentage === null ? null : (string) $r->percentage, 'submitted_at' => $r->last_submitted_at,
        ]);

        return Inertia::render('Assessments/Reviews/Index', [
            'rows' => $rows, 'filters' => $filters,
            'cycles' => AssessmentCycle::query()->orderByDesc('period_end')->limit(50)->get(['id', 'code', 'name_en', 'name_am']),
        ]);
    }

    public function show(Request $request, AssessmentRecord $record): Response
    {
        /** @var User $user */
        $user = $request->user();
        $manage = $this->assignments->canManage($user, $record);
        abort_unless($this->reviews->canReview($user, $record) || $manage || ($user->can('assessment_assignments.view') && $this->scope->canAccessOrganization($user, $record->organization_id)), 403);
        $detailed = $user->can('assessment_results.view_detailed') && $this->scope->canAccessOrganization($user, $record->organization_id) && $user->employee_id !== $record->employee_id;
        $responses = $record->responses()->with(['evaluator:id,name', 'items:id,response_id,criterion_id,rating_option_id,score_snapshot,comment', 'revisions'])->orderBy('created_at')->get();

        return Inertia::render('Assessments/Reviews/Show', [
            'record' => [
                ...$record->only(['id', 'status', 'reopen_reason', 'band_code', 'band_label_en', 'band_label_am', 'score_breakdown', 'unassessed_reason']),
                'percentage' => $record->percentage === null ? null : (string) $record->percentage, 'contribution' => $record->contribution === null ? null : (string) $record->contribution,
                'employee' => collect($record->employee_snapshot)->except(['gender', 'organization_id', 'unit_id', 'position_id'])->all(),
                'period' => ['start' => $record->period_start?->toDateString(), 'end' => $record->period_end?->toDateString()],
                'finalized_at' => $record->finalized_at?->toIso8601String(), 'cycle' => $record->cycle?->only(['code', 'name_en', 'name_am']),
            ],
            'definition' => $detailed ? $this->definitions->for($record->version) : null,
            'responses' => $responses->map(fn (AssessmentResponse $r): array => [
                ...$r->only(['id', 'evaluator_type', 'status', 'submitted_late', 'late_reason', 'return_reason', 'conflict_reason', 'submission_count', 'is_anonymous']),
                // Anonymous components keep their evaluator hidden unless the viewer holds detailed-results access.
                'evaluator' => ($r->is_anonymous && ! $detailed) ? null : $r->evaluator?->name,
                'submitted_at' => $r->submitted_at?->toIso8601String(), 'due_at' => $r->due_at?->toDateString(),
                'percentage' => $r->status === 'submitted' ? ($r->score_snapshot['percentage'] ?? null) : null,
                'raw' => $r->status === 'submitted' ? ($r->score_snapshot['raw'] ?? null) : null,
                'items' => $detailed && $r->status === 'submitted' ? $r->items->map->only(['criterion_id', 'rating_option_id', 'score_snapshot', 'comment'])->values() : [],
                'revisions' => $detailed ? $r->revisions->map(fn ($v) => ['revision_no' => $v->revision_no, 'returned_at' => $v->returned_at?->toIso8601String(), 'return_reason' => $v->return_reason])->values() : [],
                'can' => [
                    'return' => $r->status === 'submitted' && ! $record->isFinalized() && $user->can('assessments.return_for_correction') && $this->reviews->canReview($user, $record),
                    'reassign' => $manage && in_array($r->status, ['not_started', 'in_progress', 'returned', 'conflict_declared'], true) && ! $record->isFinalized(),
                    'rejectConflict' => $manage && $r->status === 'conflict_declared',
                ],
            ])->values(),
            'slots' => $this->assignments->openSlots($record),
            'changes' => $record->evaluatorChanges()->with('changer:id,name')->get()->map(fn ($c) => [
                'type' => $c->evaluator_type, 'reason_code' => $c->reason_code, 'reason' => $c->reason, 'changer' => $c->changer?->name, 'at' => $c->created_at?->toIso8601String(),
                'from' => $detailed ? User::query()->whereKey($c->from_evaluator_id)->value('name') : null, 'to' => $detailed ? User::query()->whereKey($c->to_evaluator_id)->value('name') : null,
            ]),
            'candidates' => $manage ? $this->candidates($record) : [],
            'can' => [
                'finalize' => $record->status === 'submitted' && $this->reviews->canFinalize($user, $record),
                'reopen' => $record->isFinalized() && $this->reviews->canFinalize($user, $record),
                'assign' => $manage && ! $record->isFinalized(),
                'detailed' => $detailed,
            ],
        ]);
    }

    public function returnResponse(Request $request, AssessmentResponse $response): RedirectResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'max:2000']])['reason'];
        $this->reviews->returnForCorrection($request->user(), $response, $reason);

        return back()->with('success', __('assessments.execution.returned'));
    }

    public function finalize(Request $request, AssessmentRecord $record): RedirectResponse
    {
        $comment = $request->validate(['comment' => ['nullable', 'string', 'max:2000']])['comment'] ?? null;
        $this->reviews->finalize($request->user(), $record, $comment);

        return back()->with('success', __('assessments.execution.finalized'));
    }

    public function reopen(Request $request, AssessmentRecord $record): RedirectResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'max:2000']])['reason'];
        $this->reviews->reopen($request->user(), $record, $reason);

        return back()->with('success', __('assessments.execution.reopened'));
    }

    public function reassign(Request $request, AssessmentResponse $response): RedirectResponse
    {
        $data = $request->validate([
            'evaluator_id' => ['required', 'integer', 'exists:users,id'],
            'reason_code' => ['required', Rule::in(['transferred', 'unavailable', 'left_employment', 'conflict', 'incorrect'])],
            'reason' => ['required', 'string', 'max:2000'],
        ]);
        $this->assignments->reassign($request->user(), $response, (int) $data['evaluator_id'], $data['reason_code'], $data['reason']);

        return back()->with('success', __('assessments.execution.reassigned'));
    }

    public function assignEvaluators(Request $request, AssessmentRecord $record): RedirectResponse
    {
        $data = $request->validate([
            'evaluator_type' => ['required', 'string', 'max:30'],
            'evaluator_ids' => ['required', 'array', 'min:1', 'max:50'], 'evaluator_ids.*' => ['integer', 'distinct', 'exists:users,id'],
        ]);
        $this->assignments->assignEvaluators($request->user(), $record, $data['evaluator_type'], $data['evaluator_ids']);
        $this->assignments->notify($record->responses()->where('status', 'not_started')->whereIn('evaluator_id', $data['evaluator_ids'])->get());

        return back()->with('success', __('assessments.execution.evaluators_assigned'));
    }

    public function rejectConflict(Request $request, AssessmentResponse $response): RedirectResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'max:2000']])['reason'];
        $this->assignments->rejectConflict($request->user(), $response, $reason);

        return back();
    }

    /** Active accounts linked to employees of the record's institution (no free search across the city). */
    private function candidates(AssessmentRecord $record): array
    {
        return User::query()->where('status', 'active')->whereNotNull('employee_id')
            ->whereIn('employee_id', \App\Models\EmployeeAssignment::query()->where('organization_id', $record->organization_id)->where('is_current', true)->select('employee_id'))
            ->orderBy('name')->limit(300)->get(['id', 'name'])->map->only(['id', 'name'])->all();
    }
}
