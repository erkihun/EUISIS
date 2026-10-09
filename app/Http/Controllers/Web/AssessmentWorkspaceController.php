<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AssessmentResponse;
use App\Models\AssessmentResponseEvidence;
use App\Models\User;
use App\Services\Assessment\Execution\AssessmentAssignmentService;
use App\Services\Assessment\Execution\AssessmentFormDefinition;
use App\Services\Assessment\Execution\AssessmentResponseService;
use App\Services\Assessment\Execution\AssessmentReviewService;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Evaluator workspace (docs/assessment-evaluator-workflow.md): the
 * assessments assigned to the signed-in user and the dynamic form renderer.
 * The list starts from the user's own evaluator assignments; there is no
 * employee search and no way to open someone else's assignment.
 */
class AssessmentWorkspaceController extends Controller
{
    public const STATUSES = ['not_started', 'in_progress', 'submitted', 'returned', 'conflict_declared'];

    public function __construct(
        private readonly AssessmentResponseService $responses,
        private readonly AssessmentFormDefinition $definitions,
        private readonly AssessmentAssignmentService $assignments,
        private readonly AssessmentReviewService $reviews,
        private readonly OrganizationScopeService $scope,
    ) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->can('assessments.view_assigned'), 403);
        $filters = array_filter($request->validate([
            'cycle' => ['nullable', 'uuid'], 'status' => ['nullable', Rule::in([...self::STATUSES, 'due_soon', 'overdue', 'completed'])],
            'form' => ['nullable', 'uuid'], 'unit' => ['nullable', 'uuid'],
        ]));
        $today = now()->toDateString();
        $base = AssessmentResponse::query()->where('assessment_responses.evaluator_id', $user->id)->where('assessment_responses.status', '!=', 'cancelled')
            ->join('assessment_records as r', 'r.id', '=', 'assessment_responses.assessment_record_id')
            ->whereNotIn('r.status', ['cancelled', 'pending_assignment', 'unassessed']);

        $counts = (clone $base)->toBase()->selectRaw("COUNT(*) AS assigned,
            SUM(CASE WHEN assessment_responses.status = 'not_started' THEN 1 ELSE 0 END) AS not_started,
            SUM(CASE WHEN assessment_responses.status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress,
            SUM(CASE WHEN assessment_responses.status = 'submitted' THEN 1 ELSE 0 END) AS submitted,
            SUM(CASE WHEN assessment_responses.status = 'returned' THEN 1 ELSE 0 END) AS returned,
            SUM(CASE WHEN r.status IN ('reviewed', 'acknowledged') THEN 1 ELSE 0 END) AS completed,
            SUM(CASE WHEN assessment_responses.status IN ('not_started', 'in_progress', 'returned') AND assessment_responses.due_at < ? THEN 1 ELSE 0 END) AS overdue", [$today])->first();
        // "Due soon" depends on each cycle's reminder window (none configured = no due-soon state).
        // An evaluator's open work is small, so it is evaluated per assignment.
        $open = (clone $base)->whereIn('assessment_responses.status', ['not_started', 'in_progress', 'returned'])->whereNotNull('assessment_responses.due_at')
            ->leftJoin('assessment_cycles as c', 'c.id', '=', 'r.assessment_cycle_id')
            ->get(['assessment_responses.id', 'assessment_responses.status', 'assessment_responses.due_at', 'c.reminder_days_before']);
        $dueSoonIds = $open->filter(fn ($r) => $this->deadline($r, $r->reminder_days_before === null ? null : (int) $r->reminder_days_before) === 'due_soon')->pluck('id')->all();

        $rows = (clone $base)
            ->when($filters['cycle'] ?? null, fn ($q, $v) => $q->where('r.assessment_cycle_id', $v))
            ->when($filters['unit'] ?? null, fn ($q, $v) => $q->where('r.organization_unit_id', $v))
            ->when($filters['form'] ?? null, fn ($q, $v) => $q->whereIn('r.form_version_id', DB::table('assessment_form_versions')->where('form_id', $v)->select('id')))
            ->when($filters['status'] ?? null, function ($q, $v) use ($today, $dueSoonIds): void {
                match ($v) {
                    'overdue' => $q->whereIn('assessment_responses.status', ['not_started', 'in_progress', 'returned'])->whereDate('assessment_responses.due_at', '<', $today),
                    'due_soon' => $q->whereIn('assessment_responses.id', $dueSoonIds ?: ['-']),
                    'completed' => $q->whereIn('r.status', ['reviewed', 'acknowledged']),
                    default => $q->where('assessment_responses.status', $v),
                };
            })
            ->select('assessment_responses.*')
            ->with(['record:id,employee_snapshot,form_version_id,assessment_cycle_id,status,period_start,period_end', 'record.version:id,form_id,name_en,name_am,version_no', 'record.cycle:id,code,name_en,name_am,reminder_days_before'])
            ->orderByRaw("CASE assessment_responses.status WHEN 'returned' THEN 0 WHEN 'in_progress' THEN 1 WHEN 'not_started' THEN 2 ELSE 3 END")
            ->orderBy('assessment_responses.due_at')->orderBy('assessment_responses.id')
            ->paginate(25)->withQueryString();

        $progress = $this->progress(collect($rows->items()));
        $page = $rows->through(fn (AssessmentResponse $r): array => [
            'id' => $r->id, 'status' => $r->status, 'evaluator_type' => $r->evaluator_type, 'due_at' => $r->due_at?->toDateString(),
            'deadline' => $this->deadline($r, $r->record->cycle?->reminder_days_before), 'record_status' => $r->record->status,
            'employee' => collect($r->record->employee_snapshot)->only(['name', 'name_en', 'number', 'position', 'position_am', 'unit', 'unit_am', 'organization', 'organization_am'])->all(),
            'form' => $r->record->version?->only(['name_en', 'name_am', 'version_no']),
            'cycle' => $r->record->cycle?->only(['id', 'code', 'name_en', 'name_am']),
            'progress' => $progress[$r->id] ?? ['answered' => 0, 'required' => 0],
        ]);

        return Inertia::render('Assessments/Workspace/Index', [
            'rows' => $page, 'filters' => $filters,
            'counts' => [...collect((array) $counts)->map(fn ($n): int => (int) $n)->all(), 'due_soon' => count($dueSoonIds)],
            'options' => $this->filterOptions($user),
        ]);
    }

    public function show(Request $request, AssessmentResponse $response): Response
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($this->responses->canOpen($user, $response), 403);
        $record = $response->record;
        try {
            $this->responses->assertFormAssignment($record);
            $blocked = null;
        } catch (HttpException $e) {
            $blocked = $e->getMessage();
        }
        $definition = $this->definitions->for($record->version);
        $items = $response->items()->get(['criterion_id', 'rating_option_id', 'comment']);

        return Inertia::render('Assessments/Workspace/Assess', [
            'definition' => $definition,
            'response' => [
                ...$response->only(['id', 'status', 'evaluator_type', 'lock_version', 'submission_count', 'return_reason', 'submitted_late']),
                'due_at' => $response->due_at?->toDateString(), 'deadline' => $this->deadline($response, $record->cycle?->reminder_days_before),
                'submitted_at' => $response->submitted_at?->toIso8601String(), 'last_saved_at' => $response->last_saved_at?->toIso8601String(),
                'answers' => $items->pluck('rating_option_id', 'criterion_id')->filter()->all(),
                'comments' => $items->pluck('comment', 'criterion_id')->filter()->all(),
                'evidence' => $response->evidence()->get(['id', 'criterion_id', 'original_name', 'size'])->groupBy('criterion_id'),
            ],
            'employee' => collect($record->employee_snapshot)->except(['gender', 'organization_id', 'unit_id', 'position_id'])->all(),
            'period' => ['start' => $record->period_start?->toDateString(), 'end' => $record->period_end?->toDateString()],
            'cycle' => $record->cycle?->only(['id', 'code', 'name_en', 'name_am', 'late_submission_policy']),
            'blocked' => $blocked,
            'can' => ['edit' => $blocked === null && $this->responses->canEdit($user, $response), 'submit' => $blocked === null && $this->responses->canEdit($user, $response) && $user->can('assessments.submit'),
                'conflict' => $this->responses->canEdit($user, $response)],
            'evidence' => ['mimes' => AssessmentResponseService::EVIDENCE_MIMES, 'max_kb' => AssessmentResponseService::EVIDENCE_MAX_KB],
        ]);
    }

    /** Autosave / explicit draft save (JSON). */
    public function draft(Request $request, AssessmentResponse $response): JsonResponse
    {
        $data = $this->answers($request);
        $lock = $this->responses->saveDraft($request->user(), $response, $data['answers'], $data['comments'], (int) $data['lock_version']);

        return response()->json(['lock_version' => $lock, 'saved_at' => now()->toIso8601String()]);
    }

    public function submit(Request $request, AssessmentResponse $response): RedirectResponse
    {
        $data = $this->answers($request) + $request->validate(['late_reason' => ['nullable', 'string', 'max:2000']]);
        $this->responses->submit($request->user(), $response, $data['answers'], $data['comments'], (int) $data['lock_version'], $data['late_reason'] ?? null);

        return redirect()->route('assessment-workspace.index')->with('success', __('assessments.execution.submitted'));
    }

    public function conflict(Request $request, AssessmentResponse $response): RedirectResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'max:2000']])['reason'];
        $this->assignments->declareConflict($request->user(), $response, $reason);

        return redirect()->route('assessment-workspace.index')->with('success', __('assessments.execution.conflict_declared'));
    }

    public function uploadEvidence(Request $request, AssessmentResponse $response): RedirectResponse
    {
        $data = $request->validate([
            'criterion_id' => ['required', 'uuid'],
            'file' => ['required', 'file', 'mimes:'.implode(',', AssessmentResponseService::EVIDENCE_MIMES), 'max:'.AssessmentResponseService::EVIDENCE_MAX_KB],
        ]);
        $this->responses->attachEvidence($request->user(), $response, $data['criterion_id'], $data['file']);

        return back()->with('success', __('assessments.execution.evidence_uploaded'));
    }

    public function removeEvidence(Request $request, AssessmentResponseEvidence $evidence): RedirectResponse
    {
        $this->responses->removeEvidence($request->user(), $evidence);

        return back();
    }

    /** Evidence is private: its evaluator, or an authorized detailed-results reviewer in scope. */
    public function downloadEvidence(Request $request, AssessmentResponseEvidence $evidence): StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();
        $record = $evidence->response->record;
        abort_unless($this->responses->canOpen($user, $evidence->response)
            || ($user->can('assessment_results.view_detailed') && $this->scope->canAccessOrganization($user, $record->organization_id) && $user->employee_id !== $record->employee_id), 403);

        return Storage::disk($evidence->disk)->download($evidence->path, $evidence->original_name);
    }

    /** @return array{answers: array<string, ?string>, comments: array<string, ?string>, lock_version: int} */
    private function answers(Request $request): array
    {
        $data = $request->validate([
            'answers' => ['present', 'array', 'max:500'], 'answers.*' => ['nullable', 'uuid'],
            'comments' => ['nullable', 'array', 'max:500'], 'comments.*' => ['nullable', 'string', 'max:5000'],
            'lock_version' => ['required', 'integer', 'min:0'],
        ]);

        return ['answers' => $data['answers'] ?? [], 'comments' => $data['comments'] ?? [], 'lock_version' => (int) $data['lock_version']];
    }

    /** answered required / total required, per response, in two grouped queries. */
    private function progress($responses): array
    {
        if ($responses->isEmpty()) {
            return [];
        }
        $required = DB::table('assessment_criteria')->whereIn('form_version_id', $responses->pluck('record.form_version_id')->unique())->where('is_required', true)
            ->selectRaw('form_version_id, COUNT(*) as n')->groupBy('form_version_id')->pluck('n', 'form_version_id');
        $answered = DB::table('assessment_response_items as i')->join('assessment_criteria as c', 'c.id', '=', 'i.criterion_id')
            ->whereIn('i.response_id', $responses->pluck('id'))->whereNotNull('i.rating_option_id')->where('c.is_required', true)
            ->selectRaw('i.response_id, COUNT(*) as n')->groupBy('i.response_id')->pluck('n', 'response_id');

        return $responses->mapWithKeys(fn (AssessmentResponse $r): array => [$r->id => ['answered' => (int) ($answered[$r->id] ?? 0), 'required' => (int) ($required[$r->record->form_version_id] ?? 0)]])->all();
    }

    /** on_track | due_soon | overdue | null (no deadline); due_soon only with the cycle's configured reminder window. */
    private function deadline(object $response, ?int $window): ?string
    {
        if ($response->due_at === null || ! in_array($response->status, ['not_started', 'in_progress', 'returned'], true)) {
            return null;
        }
        $today = now()->toDateString();
        $due = \Illuminate\Support\Carbon::parse($response->due_at);
        if ($today > $due->toDateString()) {
            return 'overdue';
        }

        return $window !== null && $today >= $due->copy()->subDays($window)->toDateString() ? 'due_soon' : 'on_track';
    }

    private function filterOptions(User $user): array
    {
        $records = DB::table('assessment_responses as s')->join('assessment_records as r', 'r.id', '=', 's.assessment_record_id')
            ->where('s.evaluator_id', $user->id)->where('s.status', '!=', 'cancelled');

        return [
            'cycles' => DB::table('assessment_cycles')->whereIn('id', (clone $records)->select('r.assessment_cycle_id'))->orderByDesc('period_end')->get(['id', 'code', 'name_en', 'name_am']),
            'forms' => DB::table('assessment_forms')->whereIn('id', DB::table('assessment_form_versions')->whereIn('id', (clone $records)->select('r.form_version_id'))->select('form_id'))->orderBy('code')->get(['id', 'code', 'name_en', 'name_am']),
            'units' => DB::table('organization_units')->whereIn('id', (clone $records)->select('r.organization_unit_id'))->orderBy('name_en')->get(['id', 'name_en', 'name_am']),
        ];
    }
}
