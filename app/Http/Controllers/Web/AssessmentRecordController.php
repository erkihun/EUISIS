<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AssessmentForm;
use App\Models\AssessmentRecord;
use App\Models\Employee;
use App\Models\User;
use App\Services\Assessment\AssessmentRecordService;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class AssessmentRecordController extends Controller
{
    public function __construct(private AssessmentRecordService $records, private OrganizationScopeService $scope) {}

    public function index(Request $request)
    {
        $actor = $request->user();
        $manage = $actor->can('assessment_forms.edit_draft');
        $filters = array_filter($request->validate([
            'period_start' => ['nullable', 'date_format:Y-m-d'], 'period_end' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:period_start'],
            'status' => ['nullable', Rule::in(['assigned', 'submitted', 'reviewed', 'acknowledged', 'unassessed'])],
        ]));
        $query = AssessmentRecord::query()->where(function ($q) use ($actor, $manage): void {
            $q->where('reviewer_id', $actor->id)->orWhereHas('responses', fn ($r) => $r->where('evaluator_id', $actor->id));
            if ($actor->employee) { $q->orWhere('employee_id', $actor->employee->id); }
            if ($manage) {
                if ($this->scope->isUnrestricted($actor)) { $q->orWhereRaw('1 = 1'); }
                else { $q->orWhereIn('organization_id', $this->scope->allowedOrganizationIds($actor)); }
            }
        })->when($filters['period_start'] ?? null, fn ($q, $value) => $q->whereDate('period_start', $value))
            ->when($filters['period_end'] ?? null, fn ($q, $value) => $q->whereDate('period_end', $value));
        $summary = null;
        if ($manage) {
            // Administrative summary only; participant grants cannot widen its organization scope.
            // Counted in SQL; result bands and gender use the configured band policy in Assessment Oversight, never fixed ranges here.
            $counts = $this->scope->applyOrganizationScope(clone $query, $actor)->toBase()->reorder()
                ->selectRaw("COUNT(*) AS total,
                    SUM(CASE WHEN status = 'assigned' THEN 1 ELSE 0 END) AS awaiting_ratings,
                    SUM(CASE WHEN status = 'submitted' THEN 1 ELSE 0 END) AS awaiting_review,
                    SUM(CASE WHEN status IN ('reviewed', 'acknowledged') THEN 1 ELSE 0 END) AS completed,
                    SUM(CASE WHEN status = 'unassessed' THEN 1 ELSE 0 END) AS unassessed,
                    SUM(CASE WHEN unassessed_reason = 'illness' THEN 1 ELSE 0 END) AS illness,
                    SUM(CASE WHEN unassessed_reason = 'other' THEN 1 ELSE 0 END) AS other,
                    SUM(CASE WHEN status IN ('assigned', 'submitted') THEN 1 ELSE 0 END) AS pending")->first();
            $summary = collect((array) $counts)->map(fn ($n): int => (int) $n)->all();
        }
        $forms = $manage ? AssessmentForm::query()->where('status', 'active')->whereNotNull('current_version_id')
            ->when(! $this->scope->isUnrestricted($actor), fn ($q) => $q->where(fn ($s) => $s->whereNull('organization_id')->orWhereIn('organization_id', $this->scope->allowedOrganizationIds($actor))))
            ->get(['id', 'code', 'name_en', 'name_am']) : [];
        $employees = $manage ? Employee::query()->whereHas('assignments', fn ($q) => $this->scope->applyOrganizationScope($q, $actor))
            ->orderBy('employee_number')->limit(500)->get(['id', 'employee_number', 'full_name']) : [];
        $users = $manage ? User::query()->where('status', 'active')->where(function ($q) use ($actor): void {
            $q->whereHas('employee.assignments', fn ($s) => $this->scope->applyOrganizationScope($s, $actor))
                ->orWhere('id', $actor->id);
        })->orderBy('name')->limit(500)->get(['id', 'name']) : [];
        return Inertia::render('Assessments/Records/Index', [
            'records' => $query->when($filters['status'] ?? null, fn ($q, $value) => $q->where('status', $value))
                ->with(['version', 'reviewer'])
                ->withCount(['responses as evaluators_total', 'responses as evaluators_submitted' => fn ($r) => $r->whereNotNull('submitted_at')])
                ->latest()->paginate(25)->withQueryString()->through(fn ($r) => $this->present($actor, $r)),
            'canManage' => $manage, 'forms' => $forms, 'employees' => $employees, 'users' => $users, 'summary' => $summary, 'filters' => $filters,
        ]);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->can('assessment_forms.edit_draft'), 403);
        $data = $request->validate([
            'employee_id' => ['required', 'uuid', 'exists:employees,id'], 'form_id' => ['required', 'uuid', 'exists:assessment_forms,id'],
            'period_start' => ['required', 'date_format:Y-m-d'], 'period_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_start'],
            'reviewer_id' => ['required', 'integer', 'exists:users,id'], 'evaluator_ids' => ['required', 'array', 'min:1', 'max:50'],
            'evaluator_ids.*' => ['required', 'integer', 'distinct', 'exists:users,id'],
        ]);
        return redirect()->route('assessment-records.show', $this->records->assign($request->user(), $data));
    }

    public function show(Request $request, AssessmentRecord $record)
    {
        $actor = $request->user();
        abort_unless($this->records->visible($actor, $record), 403);
        $record->load('version.sections.criteria.options', 'reviewer');
        $response = $record->responses()->where('evaluator_id', $actor->id)->first();
        return Inertia::render('Assessments/Records/Show', [
            'record' => $this->present($actor, $record), 'version' => [...$record->version->only(['name_en', 'name_am', 'version_no']), 'sections' => $record->version->sections],
            'response' => $response?->only(['answers', 'submitted_at']),
            // Same rules as the evaluator workspace and review services (one engine).
            'can' => ['submit' => $response !== null && app(\App\Services\Assessment\Execution\AssessmentResponseService::class)->canEdit($actor, $response) && $actor->can('assessments.submit'),
                'review' => $record->status === 'submitted' && app(\App\Services\Assessment\Execution\AssessmentReviewService::class)->canFinalize($actor, $record),
                'acknowledge' => $actor->employee?->id === $record->employee_id && $record->status === 'reviewed' && (bool) $record->version->acknowledgement_required,
                'unassessed' => $this->records->manages($actor, $record->organization_id) && in_array($record->status, ['assigned', 'pending_assignment'], true) && ! $record->responses()->where('status', 'submitted')->exists(),
                'workspace' => $response !== null && $response->status !== 'cancelled'],
            'workspaceUrl' => $response !== null ? route('assessment-workspace.show', $response->id) : null,
        ]);
    }

    public function submit(Request $request, AssessmentRecord $record)
    {
        $data = $request->validate(['answers' => ['required', 'array'], 'answers.*' => ['required', 'uuid']]);
        $this->records->submit($request->user(), $record, $data['answers']);
        return back();
    }

    public function transition(Request $request, AssessmentRecord $record, string $action)
    {
        $data = $action === 'unassessed' ? $request->validate(['reason' => ['required', Rule::in(['illness', 'other'])], 'note' => ['nullable', 'string', 'max:2000']]) : [];
        $this->records->transition($request->user(), $record, $action, $data);
        return back();
    }

    private function present(User $actor, AssessmentRecord $record): array
    {
        $resultVisible = in_array($record->status, ['reviewed', 'acknowledged'], true) || $this->records->manages($actor, $record->organization_id) || $actor->id === $record->reviewer_id;
        return [...$record->only(['id', 'status', 'employee_snapshot', 'reviewed_at', 'acknowledged_at', 'unassessed_reason']),
            'period_start' => $record->period_start->toDateString(), 'period_end' => $record->period_end->toDateString(),
            'version' => $record->version->only(['name_en', 'name_am', 'version_no']), 'reviewer' => $record->reviewer?->only(['name']),
            'percentage' => $resultVisible ? $record->percentage : null, 'contribution' => $resultVisible ? $record->contribution : null,
            // Progress only (how many evaluators have submitted), never who rated what.
            'evaluators' => isset($record->evaluators_total) ? ['submitted' => (int) $record->evaluators_submitted, 'total' => (int) $record->evaluators_total] : null];
    }
}
