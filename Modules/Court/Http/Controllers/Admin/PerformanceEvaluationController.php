<?php

declare(strict_types=1);

namespace Modules\Court\Http\Controllers\Admin;

use Modules\Court\Http\Controllers\Controller;
use Modules\Court\Domain\Models\PerformanceCriterion;
use Modules\Court\Domain\Models\PerformanceEvaluation;
use Modules\Court\Domain\Models\PerformanceEvaluationCategory;
use Modules\Court\Domain\Models\PerformanceEvaluationScore;
use Modules\Court\Domain\Models\CourtStaffMember as User;
use Modules\Court\Application\Services\AppealRecords;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Inertia\Inertia;

class PerformanceEvaluationController extends Controller
{
    public function index(Request $request)
    {
        $query = PerformanceEvaluation::with(['evaluatedUser', 'evaluator'])
            ->orderByDesc('period_start');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('user_id')) {
            $query->where('evaluated_user_id', $request->user_id);
        }
        if ($request->filled('period_type')) {
            $query->where('period_type', $request->period_type);
        }

        $evaluations = $query->paginate(15)->withQueryString();
        $users = User::where('status', 'active')->orderBy('name')->get(['id', 'name']);
        $categories = PerformanceEvaluationCategory::query()
            ->where('active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        // Summary stats
        $stats = [
            'total'     => PerformanceEvaluation::count(),
            'draft'     => PerformanceEvaluation::where('status', 'draft')->count(),
            'submitted' => PerformanceEvaluation::where('status', 'submitted')->count(),
            'reviewed'  => PerformanceEvaluation::where('status', 'reviewed')->count(),
            // MySQL answers AVG() of a decimal column as a string, which round() refuses under strict_types.
            'avg_score' => round((float) (PerformanceEvaluation::where('status', 'reviewed')->avg('overall_score') ?? 0), 1),
        ];

        $user = $request->user();
        $can = fn (string $permission): bool => (bool) ($user?->hasPermission($permission) ?? false);

        // The register (React, Court/Performance/Index).
        return Inertia::render('Court/Performance/Index', [
            'evaluations' => $evaluations->through(fn (PerformanceEvaluation $evaluation): array => [
                'id' => (int) $evaluation->id,
                'member_name' => $evaluation->evaluatedUser?->name,
                'member_avatar_url' => $evaluation->evaluatedUser?->avatar_url,
                'period_type' => (string) $evaluation->period_type,
                'period_start' => $evaluation->period_start?->toDateString(),
                'period_end' => $evaluation->period_end?->toDateString(),
                'overall_score' => (float) $evaluation->overall_score,
                'status' => (string) $evaluation->status,
                'evaluator_name' => $evaluation->evaluator?->name,
                'created_at' => $evaluation->created_at?->toIso8601String(),
            ]),
            'users' => $this->userOptions($users),
            'categories' => $categories->map(fn (PerformanceEvaluationCategory $category): array => [
                'slug' => (string) $category->slug,
                'name' => (string) $category->local_name,
            ])->values()->all(),
            'stats' => [
                'total' => (int) $stats['total'],
                'draft' => (int) $stats['draft'],
                'submitted' => (int) $stats['submitted'],
                'reviewed' => (int) $stats['reviewed'],
                'avg_score' => (float) $stats['avg_score'],
            ],
            'filters' => [
                'status' => (string) $request->query('status', ''),
                'user_id' => (string) $request->query('user_id', ''),
                'period_type' => (string) $request->query('period_type', ''),
            ],
            'can' => [
                'create' => $can('performance-evaluations.create'),
                'update' => $can('performance-evaluations.update'),
                'manageSettings' => $can('settings.manage'),
            ],
            // The Add Criterion / Add Category dialogs come back here (redirect_to).
            'returnUrl' => $request->fullUrl(),
        ]);
    }

    public function create()
    {
        $users    = User::where('status', 'active')->orderBy('name')->get(['id', 'name', 'avatar_path']);
        $criteria = PerformanceCriterion::active()->get();

        // The new evaluation form (React, Court/Performance/Form).
        return Inertia::render('Court/Performance/Form', [
            'evaluation' => null,
            'users' => $this->userOptions($users),
            'criteria' => $this->criterionOptions($criteria),
            'scores' => [],
            'can' => ['delete' => false],
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'evaluated_user_id' => ['required', 'exists:court_staff,id'],
            'period_type'       => ['required', 'in:monthly,quarterly,annual'],
            'period_start'      => ['required', 'date'],
            'period_end'        => ['required', 'date', 'after_or_equal:period_start'],
            'notes'             => ['nullable', 'string', 'max:2000'],
            'scores'            => ['required', 'array'],
            'scores.*.criterion_id' => ['required', 'exists:court_performance_evaluation_criteria,id'],
            'scores.*.score'        => ['required', 'integer', 'min:0', 'max:10'],
            'scores.*.comment'      => ['nullable', 'string', 'max:500'],
            'action'            => ['required', 'in:draft,submitted'],
        ], [], __('court::performance.validation.attributes'));

        DB::transaction(function () use ($data, $request) {
            $evaluation = PerformanceEvaluation::create([
                'evaluated_user_id' => $data['evaluated_user_id'],
                'evaluator_id'      => auth()->id(),
                'period_type'       => $data['period_type'],
                'period_start'      => $data['period_start'],
                'period_end'        => $data['period_end'],
                'notes'             => $data['notes'] ?? null,
                'status'            => $data['action'],
            ]);

            $this->saveScores($evaluation, $data['scores']);

            $evaluation->recalculateScore();
        });

        $msg = $data['action'] === 'submitted'
            ? __('court::performance.messages.submitted')
            : __('court::performance.messages.draft');

        return redirect()->route('court.performance-evaluations.index')->with('success', $msg);
    }

    public function show(PerformanceEvaluation $performanceEvaluation)
    {
        $performanceEvaluation->load([
            'evaluatedUser', 'evaluator', 'reviewer',
            'scores.criterion',
        ]);

        $evaluation = $performanceEvaluation;
        $user = auth()->user();
        $can = fn (string $permission): bool => (bool) ($user?->hasPermission($permission) ?? false);
        $categoryNames = $this->categoryNames();

        // One evaluation (React, Court/Performance/Show).
        return Inertia::render('Court/Performance/Show', [
            'evaluation' => [
                'id' => (int) $evaluation->id,
                'member_name' => $evaluation->evaluatedUser?->name,
                'member_avatar_url' => $evaluation->evaluatedUser?->avatar_url,
                'period_type' => (string) $evaluation->period_type,
                'period_start' => $evaluation->period_start?->toDateString(),
                'period_end' => $evaluation->period_end?->toDateString(),
                'status' => (string) $evaluation->status,
                'overall_score' => (float) $evaluation->overall_score,
                'evaluator_name' => $evaluation->evaluator?->name,
                'reviewer_name' => $evaluation->reviewer?->name,
                'notes' => $evaluation->notes,
                'reviewer_notes' => $evaluation->reviewer_notes,
                'created_at' => $evaluation->created_at?->toIso8601String(),
                'reviewed_at' => $evaluation->reviewed_at?->toIso8601String(),
            ],
            'scores' => $evaluation->scores->sortBy('criterion.sort_order')->map(fn (PerformanceEvaluationScore $score): array => [
                'id' => (int) $score->id,
                'criterion_name' => $score->criterion?->local_name,
                'category_label' => $this->categoryLabel($score->criterion?->category, $categoryNames),
                'weight' => $score->criterion ? (int) $score->criterion->weight : null,
                'score' => (int) $score->score,
                'comment' => $score->comment,
            ])->values()->all(),
            'can' => [
                'update' => $can('performance-evaluations.update'),
                'review' => $can('performance-evaluations.review'),
            ],
        ]);
    }

    public function edit(PerformanceEvaluation $performanceEvaluation)
    {
        abort_if($performanceEvaluation->status === 'reviewed', 403, __('court::performance.messages.reviewed_cannot_edit'));

        $performanceEvaluation->load('scores');

        $users    = User::where('status', 'active')->orderBy('name')->get(['id', 'name', 'avatar_path']);
        $criteria = PerformanceCriterion::active()->get();

        $performanceEvaluation->loadMissing('evaluatedUser');

        // The edit form (React, Court/Performance/Form), with the saved score of each criterion.
        return Inertia::render('Court/Performance/Form', [
            'evaluation' => [
                'id' => (int) $performanceEvaluation->id,
                'evaluated_user_id' => (int) $performanceEvaluation->evaluated_user_id,
                'member_name' => $performanceEvaluation->evaluatedUser?->name,
                'period_type' => (string) $performanceEvaluation->period_type,
                'period_start' => $performanceEvaluation->period_start?->toDateString(),
                'period_end' => $performanceEvaluation->period_end?->toDateString(),
                'notes' => $performanceEvaluation->notes,
                'status' => (string) $performanceEvaluation->status,
            ],
            'users' => $this->userOptions($users),
            'criteria' => $this->criterionOptions($criteria),
            'scores' => $performanceEvaluation->scores->map(fn (PerformanceEvaluationScore $score): array => [
                'criterion_id' => (int) $score->criterion_id,
                'score' => (int) $score->score,
                'comment' => $score->comment,
            ])->values()->all(),
            'can' => ['delete' => (bool) (auth()->user()?->hasPermission('performance-evaluations.delete') ?? false)],
        ]);
    }

    public function update(Request $request, PerformanceEvaluation $performanceEvaluation)
    {
        abort_if($performanceEvaluation->status === 'reviewed', 403, __('court::performance.messages.reviewed_cannot_edit'));

        $data = $request->validate([
            'evaluated_user_id' => ['required', 'exists:court_staff,id'],
            'period_type'       => ['required', 'in:monthly,quarterly,annual'],
            'period_start'      => ['required', 'date'],
            'period_end'        => ['required', 'date', 'after_or_equal:period_start'],
            'notes'             => ['nullable', 'string', 'max:2000'],
            'scores'            => ['required', 'array'],
            'scores.*.criterion_id' => ['required', 'exists:court_performance_evaluation_criteria,id'],
            'scores.*.score'        => ['required', 'integer', 'min:0', 'max:10'],
            'scores.*.comment'      => ['nullable', 'string', 'max:500'],
            'action'            => ['required', 'in:draft,submitted'],
        ], [], __('court::performance.validation.attributes'));

        DB::transaction(function () use ($data, $performanceEvaluation) {
            $performanceEvaluation->update([
                'evaluated_user_id' => $data['evaluated_user_id'],
                'period_type'       => $data['period_type'],
                'period_start'      => $data['period_start'],
                'period_end'        => $data['period_end'],
                'notes'             => $data['notes'] ?? null,
                'status'            => $data['action'],
            ]);

            // Replace all scores
            $performanceEvaluation->scores()->delete();
            $this->saveScores($performanceEvaluation, $data['scores']);

            $performanceEvaluation->recalculateScore();
        });

        return redirect()->route('court.performance-evaluations.show', $performanceEvaluation)
            ->with('success', __('court::performance.messages.updated'));
    }

    public function destroy(PerformanceEvaluation $performanceEvaluation)
    {
        abort_if($performanceEvaluation->status === 'reviewed', 403, __('court::performance.messages.reviewed_cannot_delete'));

        $performanceEvaluation->delete();

        return redirect()->route('court.performance-evaluations.index')
            ->with('success', __('court::performance.messages.deleted'));
    }

    public function review(Request $request, PerformanceEvaluation $performanceEvaluation)
    {
        abort_if($performanceEvaluation->status !== 'submitted', 422, __('court::performance.messages.submitted_only_review'));

        $data = $request->validate([
            'reviewer_notes' => ['nullable', 'string', 'max:2000'],
        ], [], __('court::performance.validation.attributes'));

        $performanceEvaluation->update([
            'status'         => 'reviewed',
            'reviewer_notes' => $data['reviewer_notes'] ?? null,
            'reviewed_by'    => auth()->id(),
            'reviewed_at'    => now(),
        ]);

        return redirect()->route('court.performance-evaluations.show', $performanceEvaluation)
            ->with('success', __('court::performance.messages.reviewed'));
    }

    /**
     * The staff member picker.
     *
     * @param Collection<int, User> $users
     * @return list<array{id: int, name: string}>
     */
    private function userOptions(Collection $users): array
    {
        return $users->map(fn (User $member): array => ['id' => (int) $member->id, 'name' => (string) $member->name])->values()->all();
    }

    /**
     * The criteria the form scores, with the category label the Blade form showed.
     *
     * @param Collection<int, PerformanceCriterion> $criteria
     * @return list<array<string, mixed>>
     */
    private function criterionOptions(Collection $criteria): array
    {
        $categoryNames = $this->categoryNames();

        return $criteria->map(fn (PerformanceCriterion $criterion): array => [
            'id' => (int) $criterion->id,
            'name' => (string) $criterion->local_name,
            'category' => $criterion->category,
            'category_label' => $this->categoryLabel($criterion->category, $categoryNames),
            'weight' => (int) $criterion->weight,
            'description' => $criterion->description,
            // appeal_outcomes: worked out from the appellate court's outcomes, never typed in.
            'source' => $criterion->source,
        ])->values()->all();
    }

    /**
     * The evaluator's scores, then the criteria worked out from the record: the
     * appellate court's outcomes on decisions the member gave on a panel, over the
     * period (EUISIS ADR 0013 #14). With no outcome in the period, that criterion
     * is left out and the others carry the overall score.
     *
     * @param  list<array{criterion_id: int|string, score: int|string, comment?: ?string}>  $scores
     */
    private function saveScores(PerformanceEvaluation $evaluation, array $scores): void
    {
        $automatic = PerformanceCriterion::query()->where('active', true)->whereNotNull('source')->get()->keyBy('id');
        foreach ($scores as $scoreData) {
            if ($automatic->has((int) $scoreData['criterion_id'])) {
                continue;
            }
            PerformanceEvaluationScore::create([
                'evaluation_id' => $evaluation->id,
                'criterion_id' => $scoreData['criterion_id'],
                'score' => $scoreData['score'],
                'comment' => $scoreData['comment'] ?? null,
            ]);
        }
        foreach ($automatic->where('source', 'appeal_outcomes') as $criterion) {
            $record = AppealRecords::judgeRecord((int) $evaluation->evaluated_user_id, (string) $evaluation->period_start, (string) $evaluation->period_end);
            if ($record['score'] !== null) {
                PerformanceEvaluationScore::create([
                    'evaluation_id' => $evaluation->id,
                    'criterion_id' => $criterion->id,
                    'score' => $record['score'],
                    'comment' => __('court::performance.appeal_outcomes.comment', $record),
                ]);
            }
        }
    }

    /** The appeal outcomes a member's evaluation would count, for the form to show before saving. */
    public function appealRecord(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user' => ['required', 'integer', 'exists:court_staff,id'],
            'start' => ['required', 'date'],
            'end' => ['required', 'date', 'after_or_equal:start'],
        ]);

        return response()->json(['data' => AppealRecords::judgeRecord((int) $data['user'], $data['start'], $data['end'])]);
    }

    /** @return Collection<string, string> category slug => its name in the current language */
    private function categoryNames(): Collection
    {
        return PerformanceEvaluationCategory::query()
            ->get()
            ->mapWithKeys(fn (PerformanceEvaluationCategory $category): array => [(string) $category->slug => (string) $category->local_name]);
    }

    /** A criterion category's label: the performance.categories.* translation, else the category's own name, else its slug. */
    private function categoryLabel(?string $slug, Collection $categoryNames): ?string
    {
        if ($slug === null || $slug === '') {
            return null;
        }

        return Lang::has("court::performance.categories.$slug")
            ? __("court::performance.categories.$slug")
            : ($categoryNames->get($slug) ?? $slug);
    }
}
