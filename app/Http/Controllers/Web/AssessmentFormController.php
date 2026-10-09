<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Enums\Assessment\EvaluatorType;
use App\Enums\Assessment\FormVersionStatus;
use App\Enums\Assessment\InputMode;
use App\Enums\Assessment\PeriodType;
use App\Enums\Assessment\ScoringMethod;
use App\Enums\Assessment\SelectionMethod;
use App\Enums\Assessment\TargetType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Assessment\SaveAssessmentDraftRequest;
use App\Models\AssessmentCriterion;
use App\Models\AssessmentForm;
use App\Models\AssessmentFormSection;
use App\Models\AssessmentFormVersion;
use App\Models\AssessmentRatingOption;
use App\Models\AssessmentTargetRule;
use App\Models\AssessmentType;
use App\Models\Competency;
use App\Models\GradeLevel;
use App\Models\Occupation;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use App\Models\PerformanceRatingScale;
use App\Models\Position;
use App\Services\Assessment\AssessmentFormService;
use App\Services\Assessment\AssessmentScoringService;
use App\Services\Assessment\AssessmentTargetResolver;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Performance › Assessment Forms: the configurable form builder
 * (docs/assessment-form-builder.md). Forms are built here, never in code.
 */
class AssessmentFormController extends Controller
{
    public function __construct(
        private readonly AssessmentFormService $forms,
        private readonly AssessmentScoringService $scoring,
        private readonly AssessmentTargetResolver $targets,
        private readonly OrganizationScopeService $scope,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', AssessmentForm::class);
        $user = $request->user();
        $search = trim((string) $request->query('search', ''));

        $forms = AssessmentForm::query()
            ->with(['type:id,code,name_en,name_am', 'organization:id,name_en,name_am',
                'currentVersion' => fn ($query) => $query->select(['id', 'version_no', 'overall_contribution_weight', 'effective_from', 'effective_to'])->withCount('targetRules')])
            ->withCount(['versions'])
            ->withExists(['versions as has_draft' => fn ($query) => $query->where('status', FormVersionStatus::Draft->value)])
            ->when(! $this->scope->isUnrestricted($user), fn ($query) => $query->where(fn ($q) => $q->whereNull('organization_id')
                ->orWhereIn('organization_id', $this->scope->accessibleOrganizationIds($user)->all())))
            ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q
                ->where('code', ci_like_operator(), "%{$search}%")
                ->orWhere('name_en', ci_like_operator(), "%{$search}%")
                ->orWhere('name_am', ci_like_operator(), "%{$search}%")))
            ->when($request->filled('type'), fn ($query) => $query->where('assessment_type_id', $request->query('type')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->query('status')))
            ->orderBy('code')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (AssessmentForm $form): array => [
                'id' => $form->id,
                'code' => $form->code,
                'name_en' => $form->name_en,
                'name_am' => $form->name_am,
                'type' => $form->type?->only(['code', 'name_en', 'name_am']),
                'organization' => $form->organization?->only(['name_en', 'name_am']),
                'status' => $form->status,
                'current_version' => $form->currentVersion?->version_no,
                'overall_contribution_weight' => $form->currentVersion?->overall_contribution_weight,
                'effective_from' => $form->currentVersion?->effective_from?->toDateString(),
                'has_draft' => (bool) $form->has_draft,
                'versions_count' => $form->versions_count,
                'target_rules_count' => (int) ($form->currentVersion?->target_rules_count ?? 0),
            ]);

        return Inertia::render('Assessments/Forms/Index', [
            'forms' => $forms,
            'filters' => $request->only(['search', 'type', 'status']),
            'types' => $this->types(),
            'organizations' => $this->organizationOptions($request),
            'can' => [
                'create' => $user->can('assessment_forms.create'),
                'createCityWide' => $user->can('createFor', [AssessmentForm::class, null]),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:50', 'alpha_dash', Rule::unique('assessment_forms', 'code')],
            'name_en' => ['required', 'string', 'max:255'],
            'name_am' => ['nullable', 'string', 'max:255'],
            'description_en' => ['nullable', 'string', 'max:5000'],
            'description_am' => ['nullable', 'string', 'max:5000'],
            'assessment_type_id' => ['required', 'uuid', Rule::exists('assessment_types', 'id')->where('is_active', true)],
            'organization_id' => ['nullable', 'uuid', Rule::exists('organizations', 'id')],
        ]);
        $this->authorize('createFor', [AssessmentForm::class, $data['organization_id'] ?? null]);

        $form = $this->forms->create($request->user(), $data);

        return redirect()->route('assessment-forms.show', $form)->with('success', __('assessments.flash.created'));
    }

    public function show(Request $request, AssessmentForm $form): Response
    {
        $this->authorize('view', $form);
        $user = $request->user();
        $versionId = $request->query('version');
        $version = ($versionId ? $form->versions()->whereKey($versionId)->first() : null)
            ?? $form->draftVersion()
            ?? $form->currentVersion
            ?? $form->versions()->latest('version_no')->firstOrFail();
        $version->load(['sections.criteria.options', 'sections.criteria.competency:id,code,name_en,name_am', 'targetRules', 'evaluatorSchemes']);

        return Inertia::render('Assessments/Forms/Show', [
            'form' => [
                'id' => $form->id,
                'code' => $form->code,
                'name_en' => $form->name_en,
                'name_am' => $form->name_am,
                'status' => $form->status,
                'type' => $form->type?->only(['id', 'code', 'name_en', 'name_am']),
                'organization' => $form->organization?->only(['id', 'name_en', 'name_am']),
                'current_version_id' => $form->current_version_id,
            ],
            'version' => $this->presentVersion($version),
            'versions' => $form->versions()->orderByDesc('version_no')->get(['id', 'version_no', 'status', 'published_at', 'effective_from', 'effective_to'])
                ->map(fn (AssessmentFormVersion $v): array => [
                    'id' => $v->id, 'version_no' => $v->version_no, 'status' => $v->status->value,
                    'published_at' => $v->published_at?->toIso8601String(),
                    'effective_from' => $v->effective_from?->toDateString(), 'effective_to' => $v->effective_to?->toDateString(),
                ])->all(),
            'problems' => session('assessment_problems'),
            'options' => [
                'scoring_methods' => ScoringMethod::values(),
                'period_types' => PeriodType::values(),
                'input_modes' => InputMode::values(),
                'target_types' => TargetType::values(),
                'evaluator_types' => EvaluatorType::values(),
                'selection_methods' => SelectionMethod::values(),
                'result_scales' => PerformanceRatingScale::query()->where('scale_type', 'RESULT')->where('is_active', true)->orderBy('name_en')->get(['id', 'code', 'name_en', 'name_am']),
                'grade_levels' => GradeLevel::query()->orderBy('name')->pluck('name')->all(),
                'job_families' => Position::query()->whereNotNull('job_family')->where('job_family', '!=', '')->distinct()->orderBy('job_family')->limit(200)->pluck('job_family')->all(),
            ],
            'can' => [
                'edit' => $version->isDraft() && $user->can('update', $form),
                'publish' => $version->isDraft() && $user->can('publish', $form),
                'newVersion' => $form->status === 'active' && $user->can('update', $form) && $form->draftVersion() === null,
                'clone' => $user->can('cloneForm', $form),
                'archive' => $form->status === 'active' && $user->can('archive', $form),
                'discard' => $version->isDraft() && $user->can('update', $form) && $form->versions()->count() > 1,
            ],
        ]);
    }

    public function saveDraft(SaveAssessmentDraftRequest $request, AssessmentFormVersion $version): RedirectResponse
    {
        $this->forms->saveDraft($request->user(), $version, $request->draft());

        return back()->with('success', __('assessments.flash.saved'));
    }

    public function validateDraft(Request $request, AssessmentFormVersion $version): RedirectResponse
    {
        $this->authorize('update', $version->form);
        $problems = $this->forms->validate($request->user(), $version);

        return back()
            ->with('assessment_problems', array_values($problems))
            ->with($problems === [] ? 'success' : 'warning', __($problems === [] ? 'assessments.flash.valid' : 'assessments.flash.invalid'));
    }

    public function publish(Request $request, AssessmentFormVersion $version): RedirectResponse
    {
        $this->authorize('publish', $version->form);
        $this->forms->publish($request->user(), $version);

        return redirect()->route('assessment-forms.show', $version->form)->with('success', __('assessments.flash.published'));
    }

    public function newVersion(Request $request, AssessmentForm $form): RedirectResponse
    {
        $this->authorize('update', $form);
        $this->forms->newVersion($request->user(), $form);

        return redirect()->route('assessment-forms.show', $form)->with('success', __('assessments.flash.version_created'));
    }

    public function cloneForm(Request $request, AssessmentForm $form): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:50', 'alpha_dash', Rule::unique('assessment_forms', 'code')],
            'name_en' => ['required', 'string', 'max:255'],
            'name_am' => ['nullable', 'string', 'max:255'],
            'organization_id' => ['nullable', 'uuid', Rule::exists('organizations', 'id')],
        ]);
        $this->authorize('cloneForm', $form);
        $this->authorize('createFor', [AssessmentForm::class, $data['organization_id'] ?? $form->organization_id]);

        $clone = $this->forms->cloneForm($request->user(), $form, $data);

        return redirect()->route('assessment-forms.show', $clone)->with('success', __('assessments.flash.cloned'));
    }

    public function archive(Request $request, AssessmentForm $form): RedirectResponse
    {
        $this->authorize('archive', $form);
        $this->forms->archive($request->user(), $form);

        return back()->with('success', __('assessments.flash.archived'));
    }

    public function discardDraft(Request $request, AssessmentFormVersion $version): RedirectResponse
    {
        $this->authorize('update', $version->form);
        $form = $version->form;
        $this->forms->discardDraft($request->user(), $version);

        return redirect()->route('assessment-forms.show', $form)->with('success', __('assessments.flash.discarded'));
    }

    /** How an evaluator will see the version. Creates nothing. */
    public function preview(Request $request, AssessmentFormVersion $version): Response
    {
        $this->authorize('view', $version->form);
        $version->load(['sections.criteria.options', 'evaluatorSchemes']);

        return Inertia::render('Assessments/Forms/Preview', [
            'form' => $version->form->only(['id', 'code', 'name_en', 'name_am']),
            'version' => $this->presentVersion($version),
        ]);
    }

    /**
     * Which employees each published form of this type would reach on a
     * date, within the viewer's scope: counts, unmatched and conflicts.
     */
    public function assignmentPreview(Request $request, AssessmentForm $form): JsonResponse
    {
        $this->authorize('view', $form);
        $date = Carbon::parse($request->validate(['date' => ['nullable', 'date_format:Y-m-d']])['date'] ?? now()->toDateString());
        $user = $request->user();
        $scopeIds = $this->scope->isUnrestricted($user) ? null : $this->scope->accessibleOrganizationIds($user)->all();

        $preview = $this->targets->preview($form->assessment_type_id, $date, $scopeIds);
        $versions = AssessmentFormVersion::query()->whereIn('id', array_keys($preview['by_version']))->with('form:id,code,name_en,name_am')->get()->keyBy('id');

        return response()->json([
            'date' => $date->toDateString(),
            'total' => $preview['total'],
            'unmatched' => $preview['unmatched'],
            'conflicts' => $preview['conflicts'],
            'forms' => collect($preview['by_version'])->map(fn (int $count, string $id): array => [
                'code' => $versions->get($id)?->form?->code,
                'name_en' => $versions->get($id)?->form?->name_en,
                'name_am' => $versions->get($id)?->form?->name_am,
                'version_no' => $versions->get($id)?->version_no,
                'employees' => $count,
            ])->values()->all(),
        ]);
    }

    /** Search master data for target rules and criterion competencies, within scope. */
    public function lookup(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AssessmentForm::class);
        $data = $request->validate([
            'type' => ['required', Rule::in(['position', 'occupation', 'organization', 'organization_unit', 'competency'])],
            'q' => ['nullable', 'string', 'max:100'],
            'organization_id' => ['nullable', 'uuid'],
        ]);
        $q = trim((string) ($data['q'] ?? ''));
        $user = $request->user();
        $scoped = fn ($query, string $column = 'organization_id') => $this->scope->isUnrestricted($user)
            ? $query : $query->whereIn($column, $this->scope->accessibleOrganizationIds($user)->all());
        $like = fn ($query, array $columns) => $q === '' ? $query : $query->where(function ($inner) use ($columns, $q): void {
            foreach ($columns as $column) {
                $inner->orWhere($column, ci_like_operator(), "%{$q}%");
            }
        });

        $results = match ($data['type']) {
            'position' => $like($scoped(Position::query())->when($data['organization_id'] ?? null, fn ($query, $org) => $query->where('organization_id', $org)), ['title_en', 'title_am', 'job_position_code'])
                ->orderBy('title_en')->limit(20)->get(['id', 'title_en', 'title_am', 'job_position_code'])
                ->map(fn ($p): array => ['id' => $p->id, 'label_en' => trim(($p->job_position_code ? $p->job_position_code.' ' : '').$p->title_en), 'label_am' => $p->title_am]),
            'organization_unit' => $like($scoped(OrganizationUnit::query())->when($data['organization_id'] ?? null, fn ($query, $org) => $query->where('organization_id', $org)), ['name_en', 'name_am', 'code'])
                ->orderBy('name_en')->limit(20)->get(['id', 'name_en', 'name_am', 'code'])
                ->map(fn ($u): array => ['id' => $u->id, 'label_en' => trim(($u->code ? $u->code.' ' : '').$u->name_en), 'label_am' => $u->name_am]),
            'organization' => $like($scoped(Organization::query(), 'id')->when($data['organization_id'] ?? null, fn ($query, $org) => $query->whereKey($org)), ['name_en', 'name_am', 'code'])
                ->orderBy('name_en')->limit(20)->get(['id', 'name_en', 'name_am'])
                ->map(fn ($o): array => ['id' => $o->id, 'label_en' => $o->name_en, 'label_am' => $o->name_am]),
            'occupation' => $like(Occupation::query()->where('is_active', true), ['name_en', 'name_am', 'code'])
                ->orderBy('name_en')->limit(20)->get(['id', 'name_en', 'name_am', 'code'])
                ->map(fn ($o): array => ['id' => $o->id, 'label_en' => trim(($o->code ? $o->code.' ' : '').$o->name_en), 'label_am' => $o->name_am]),
            'competency' => $like(Competency::query()->where('is_active', true), ['name_en', 'name_am', 'code'])
                ->orderBy('code')->limit(20)->get(['id', 'name_en', 'name_am', 'code'])
                ->map(fn ($c): array => ['id' => $c->id, 'label_en' => $c->code.' '.$c->name_en, 'label_am' => $c->name_am]),
        };

        return response()->json(['results' => $results->values()]);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function presentVersion(AssessmentFormVersion $version): array
    {
        $targetLabels = $this->targetLabels($version);

        return [
            'id' => $version->id,
            'version_no' => $version->version_no,
            'status' => $version->status->value,
            ...$version->only(['name_en', 'name_am', 'description_en', 'description_am', 'instructions_en', 'instructions_am', 'max_total_score', 'overall_contribution_weight', 'result_scale_id', 'acknowledgement_required', 'review_required', 'show_option_scores']),
            'period_type' => $version->period_type?->value,
            'scoring_method' => $version->scoring_method?->value,
            'effective_from' => $version->effective_from?->toDateString(),
            'effective_to' => $version->effective_to?->toDateString(),
            'published_at' => $version->published_at?->toIso8601String(),
            'computed_max' => $version->relationLoaded('sections') ? $this->scoring->versionMax($version) : null,
            'sections' => $version->sections->map(fn (AssessmentFormSection $section): array => [
                ...$section->only(['id', 'code', 'title_en', 'title_am', 'description_en', 'description_am', 'weight', 'max_score', 'is_required']),
                'computed_max' => $this->scoring->sectionMax($section),
                'criteria' => $section->criteria->map(fn (AssessmentCriterion $criterion): array => [
                    ...$criterion->only(['id', 'competency_id', 'code', 'title_en', 'title_am', 'description_en', 'description_am', 'max_score', 'weight', 'is_required']),
                    'comment_mode' => $criterion->comment_mode?->value,
                    'evidence_mode' => $criterion->evidence_mode?->value,
                    'competency' => $criterion->relationLoaded('competency') && $criterion->competency ? $criterion->competency->only(['code', 'name_en', 'name_am']) : null,
                    'options' => $criterion->options->map(fn (AssessmentRatingOption $option): array => $option->only(['id', 'label_en', 'label_am', 'description_en', 'description_am', 'score']))->all(),
                ])->all(),
            ])->all(),
            'target_rules' => $version->relationLoaded('targetRules') ? $version->targetRules->map(fn (AssessmentTargetRule $rule): array => [
                ...$rule->only(['target_id', 'target_value', 'include_descendants', 'effect', 'priority']),
                'target_type' => $rule->target_type->value,
                'effective_from' => $rule->effective_from?->toDateString(),
                'effective_to' => $rule->effective_to?->toDateString(),
                'target_label' => $targetLabels[$rule->target_type->value.':'.$rule->target_id] ?? null,
            ])->all() : [],
            'evaluators' => $version->evaluatorSchemes->map(fn ($scheme): array => [
                ...$scheme->only(['required_count', 'contribution_weight', 'aggregation_method', 'is_anonymous', 'requires_review']),
                'evaluator_type' => $scheme->evaluator_type->value,
                'selection_method' => $scheme->selection_method->value,
            ])->all(),
        ];
    }

    /** @return array<string, array{label_en: string, label_am: ?string}> "type:id" => names of the rule targets */
    private function targetLabels(AssessmentFormVersion $version): array
    {
        if (! $version->relationLoaded('targetRules')) {
            return [];
        }

        $ids = fn (TargetType $type) => $version->targetRules->where('target_type', $type)->pluck('target_id')->filter()->unique()->all();
        $labels = [];
        foreach (Position::query()->whereIn('id', $ids(TargetType::Position))->get(['id', 'title_en', 'title_am']) as $p) {
            $labels['position:'.$p->id] = ['label_en' => $p->title_en, 'label_am' => $p->title_am];
        }
        foreach (OrganizationUnit::query()->whereIn('id', $ids(TargetType::OrganizationUnit))->get(['id', 'name_en', 'name_am']) as $u) {
            $labels['organization_unit:'.$u->id] = ['label_en' => $u->name_en, 'label_am' => $u->name_am];
        }
        foreach (Organization::query()->whereIn('id', $ids(TargetType::Organization))->get(['id', 'name_en', 'name_am']) as $o) {
            $labels['organization:'.$o->id] = ['label_en' => $o->name_en, 'label_am' => $o->name_am];
        }
        foreach (Occupation::query()->whereIn('id', $ids(TargetType::Occupation))->get(['id', 'name_en', 'name_am']) as $o) {
            $labels['occupation:'.$o->id] = ['label_en' => $o->name_en, 'label_am' => $o->name_am];
        }

        return $labels;
    }

    private function types(): array
    {
        return AssessmentType::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'code', 'name_en', 'name_am'])->all();
    }

    private function organizationOptions(Request $request): array
    {
        $user = $request->user();

        return Organization::query()
            ->when(! $this->scope->isUnrestricted($user), fn ($query) => $query->whereIn('id', $this->scope->accessibleOrganizationIds($user)->all()))
            ->orderBy('name_en')->limit(500)->get(['id', 'name_en', 'name_am'])->all();
    }
}
