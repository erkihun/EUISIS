<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\OrganizationType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\ServiceMonitoring\Application\Services\CustomSectionSummary;
use Modules\ServiceMonitoring\Application\Services\FormTemplateLifecycle;
use Modules\ServiceMonitoring\Application\Services\PeriodDirectory;
use Modules\ServiceMonitoring\Application\Services\ServiceMonitoringAccess;
use Modules\ServiceMonitoring\Domain\Enums\FormTemplateStatus;
use Modules\ServiceMonitoring\Domain\Enums\FormType;
use Modules\ServiceMonitoring\Domain\Enums\ReportRange;
use Modules\ServiceMonitoring\Domain\Forms\FormDefinition;
use Modules\ServiceMonitoring\Domain\Forms\FormSchema;
use Modules\ServiceMonitoring\Domain\Forms\TemplateContent;
use Modules\ServiceMonitoring\Domain\Models\FiscalYear;
use Modules\ServiceMonitoring\Domain\Models\FormTemplate;
use Modules\ServiceMonitoring\Http\Requests\DuplicateFormTemplateRequest;
use Modules\ServiceMonitoring\Http\Requests\SaveFormTemplateRequest;

/**
 * The form builder (docs/service-monitoring.md §Form templates): versioned
 * templates that re-word or switch off the official sections and add new
 * ones, assigned to organization types instead of a fixed administrative level.
 */
final class FormTemplateController extends Controller
{
    public function __construct(private readonly FormTemplateLifecycle $templates) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', FormTemplate::class);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(FormTemplateStatus::class)],
        ]);

        $templates = FormTemplate::query()
            ->with('organizationTypes:id,name_am,name_en')
            ->withCount('submissions')
            ->when($filters['search'] ?? null, static function ($query, string $search): void {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';
                $query->where(static fn ($q) => $q->where('code', 'like', $like)->orWhere('name_am', 'like', $like)->orWhere('name_en', 'like', $like));
            })
            ->when($filters['status'] ?? null, static fn ($query, string $status) => $query->where('status', $status))
            ->orderBy('code')->orderByDesc('version')
            ->paginate(25)->withQueryString()
            ->through(fn (FormTemplate $t): array => $this->row($t) + ['submissions_count' => $t->submissions_count]);

        return Inertia::render('ServiceMonitoring/Forms/Index', ['templates' => $templates, 'filters' => $filters]);
    }

    public function create(): Response
    {
        $this->authorize('create', FormTemplate::class);

        return Inertia::render('ServiceMonitoring/Forms/Create', $this->editorProps());
    }

    public function store(SaveFormTemplateRequest $request): RedirectResponse
    {
        $template = $this->templates->create($request->user(), $request->templateAttributes());

        return to_route('service-monitoring.forms.show', $template)->with('success', __('service-monitoring.form_templates.flash.saved'));
    }

    public function show(Request $request, FormTemplate $template): Response
    {
        $this->authorize('view', $template);
        $template->load('organizationTypes:id,name_am,name_en');
        $user = $request->user();
        $versions = FormTemplate::query()->where('code', $template->code)->withCount('submissions')->orderByDesc('version')
            ->get(['id', 'code', 'version', 'status', 'published_at', 'updated_at']);

        return Inertia::render('ServiceMonitoring/Forms/Show', [
            'template' => $this->detail($template),
            'versions' => $versions->map(static fn (FormTemplate $v): array => [
                'id' => $v->id, 'version' => $v->version, 'status' => $v->status->value,
                'published_at' => $v->published_at?->toIso8601String(), 'updated_at' => $v->updated_at?->toIso8601String(),
                'submissions_count' => $v->submissions_count,
            ])->all(),
            'problems' => $template->status->isEditable() ? array_values($template->contentRules()->problems(forPublish: true)) : [],
            'official' => $this->official(),
            'can' => [
                'update' => $user->can('update', $template),
                'publish' => $user->can('publish', $template),
                'archive' => $user->can('archive', $template) && $template->status !== FormTemplateStatus::Archived,
                'delete' => $user->can('delete', $template) && $template->submissions()->doesntExist(),
                'new_version' => ! $template->status->isEditable() && $versions->doesntContain(static fn (FormTemplate $v): bool => $v->status === FormTemplateStatus::Draft),
            ],
        ]);
    }

    public function edit(FormTemplate $template): Response
    {
        $this->authorize('update', $template);
        $template->load('organizationTypes:id,name_am,name_en');

        return Inertia::render('ServiceMonitoring/Forms/Edit', ['template' => $this->detail($template)] + $this->editorProps());
    }

    public function update(SaveFormTemplateRequest $request, FormTemplate $template): RedirectResponse
    {
        $this->templates->update($request->user(), $template, $request->templateAttributes());

        return to_route('service-monitoring.forms.show', $template)->with('success', __('service-monitoring.form_templates.flash.saved'));
    }

    public function publish(Request $request, FormTemplate $template): RedirectResponse
    {
        $this->authorize('publish', $template);
        $this->templates->publish($request->user(), $template);

        return to_route('service-monitoring.forms.show', $template)->with('success', __('service-monitoring.form_templates.flash.published'));
    }

    public function newVersion(Request $request, FormTemplate $template): RedirectResponse
    {
        $this->authorize('create', FormTemplate::class);
        $draft = $this->templates->newVersion($request->user(), $template);

        return to_route('service-monitoring.forms.edit', $draft)->with('success', __('service-monitoring.form_templates.flash.version_created'));
    }

    public function duplicate(DuplicateFormTemplateRequest $request, FormTemplate $template): RedirectResponse
    {
        $this->authorize('view', $template);
        $copy = $this->templates->duplicate($request->user(), $template, $request->validated('code'), $request->validated('name_am'));

        return to_route('service-monitoring.forms.edit', $copy)->with('success', __('service-monitoring.form_templates.flash.duplicated'));
    }

    public function archive(Request $request, FormTemplate $template): RedirectResponse
    {
        $this->authorize('archive', $template);
        $this->templates->archive($request->user(), $template);

        return to_route('service-monitoring.forms.show', $template)->with('success', __('service-monitoring.form_templates.flash.archived'));
    }

    public function destroy(Request $request, FormTemplate $template): RedirectResponse
    {
        $this->authorize('delete', $template);
        $this->templates->discard($request->user(), $template);

        return to_route('service-monitoring.forms.index')->with('success', __('service-monitoring.form_templates.flash.discarded'));
    }

    /** The additional sections consolidated over a report range, inside the viewer's scope. */
    public function summary(Request $request, FormTemplate $template, PeriodDirectory $periods, CustomSectionSummary $summary, ServiceMonitoringAccess $access): Response
    {
        abort_unless($access->canViewReports($request->user()), 403);
        $current = $periods->current();
        $yearId = $request->string('fiscal_year_id')->toString();
        $year = (Str::isUuid($yearId) ? FiscalYear::query()->find($yearId) : null) ?? $current?->fiscalYear ?? FiscalYear::query()->orderByDesc('ec_year')->first();
        $quarter = max(1, min(4, (int) ($request->input('quarter') ?: ($current?->quarter ?? 1))));
        $range = ReportRange::tryFrom($request->string('range')->toString()) ?? ReportRange::Quarter;

        return Inertia::render('ServiceMonitoring/Forms/Summary', [
            'template' => $this->row($template) + ['content' => $template->content],
            'years' => $periods->years(),
            'selection' => $year === null ? null : ['fiscal_year_id' => $year->id, 'quarter' => $quarter, 'range' => $range->value],
            'summary' => $year === null ? null : $summary->build($request->user(), $template, $year, $range, $quarter),
        ]);
    }

    /** @return array<string, mixed> */
    private function editorProps(): array
    {
        return [
            'organizationTypes' => OrganizationType::query()->active()->orderBy('level_order')->orderBy('sort_order')->orderBy('name_en')
                ->get(['id', 'name_am', 'name_en'])
                ->map(static fn (OrganizationType $t): array => ['id' => $t->id, 'name_am' => $t->name_am, 'name_en' => $t->name_en])->all(),
            'official' => $this->official(),
            'blank' => TemplateContent::empty(),
            'limits' => [
                'sections' => FormSchema::MAX_SECTIONS, 'blocks' => FormSchema::MAX_BLOCKS, 'columns' => FormSchema::MAX_COLUMNS,
                'fixed_rows' => FormSchema::MAX_FIXED_ROWS, 'dynamic_rows' => FormSchema::MAX_DYNAMIC_ROWS,
            ],
        ];
    }

    /**
     * The official wording a template starts from, so the builder can show
     * what an empty field keeps (Form A's text; the year is a placeholder).
     *
     * @return array<string, mixed>
     */
    private function official(): array
    {
        $definition = FormDefinition::describe(FormType::A, FormDefinition::CURRENT_VERSION, (int) (FiscalYear::query()->max('ec_year') ?? 0));

        return [
            'title' => $definition['title'],
            'general_notes' => $definition['general_notes'],
            'sections' => [
                '1' => ['title' => $definition['section_1']['title'], 'notes' => $definition['section_1']['summary']['notes']],
                '2' => ['title' => $definition['section_2']['title'], 'notes' => $definition['section_2']['notes']],
                '3' => ['title' => $definition['section_3']['title'], 'notes' => $definition['section_3']['notes']],
                '4' => ['title' => $definition['section_4']['title'], 'notes' => $definition['section_4']['notes']],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function row(FormTemplate $template): array
    {
        return [
            'id' => $template->id,
            'code' => $template->code,
            'version' => $template->version,
            'name_am' => $template->name_am,
            'name_en' => $template->name_en,
            'status' => $template->status->value,
            'published_at' => $template->published_at?->toIso8601String(),
            'updated_at' => $template->updated_at?->toIso8601String(),
            'organization_types' => $template->organizationTypes->map(static fn (OrganizationType $t): array => ['id' => $t->id, 'name_am' => $t->name_am, 'name_en' => $t->name_en])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function detail(FormTemplate $template): array
    {
        return $this->row($template) + [
            'description' => $template->description,
            'content' => $template->content,
            'organization_type_ids' => $template->organizationTypes->pluck('id')->map(static fn ($id): string => (string) $id)->values()->all(),
        ];
    }
}
