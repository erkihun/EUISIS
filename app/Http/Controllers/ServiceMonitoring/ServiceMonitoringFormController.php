<?php

declare(strict_types=1);

namespace App\Http\Controllers\ServiceMonitoring;

use App\Enums\ServiceMonitoring\FormStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\ServiceMonitoring\DuplicateServiceMonitoringFormRequest;
use App\Http\Requests\ServiceMonitoring\SaveServiceMonitoringFormRequest;
use App\Models\OrganizationType;
use App\Models\ServiceMonitoringForm;
use App\Services\ServiceMonitoring\ServiceMonitoringFormService;
use App\Support\ServiceMonitoring\FormSchema;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** The form builder (docs/service-monitoring.md §Forms). */
class ServiceMonitoringFormController extends Controller
{
    public function __construct(private readonly ServiceMonitoringFormService $forms) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', ServiceMonitoringForm::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(FormStatus::class)],
        ]);

        $forms = ServiceMonitoringForm::query()
            ->with('organizationTypes:id,name_am,name_en')
            ->withCount('submissions')
            ->when($filters['search'] ?? null, static function ($query, string $search): void {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';
                $query->where(static fn ($q) => $q->where('code', 'like', $like)->orWhere('name_am', 'like', $like)->orWhere('name_en', 'like', $like));
            })
            ->when($filters['status'] ?? null, static fn ($query, string $status) => $query->where('status', $status))
            ->orderBy('code')
            ->orderByDesc('version')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (ServiceMonitoringForm $form): array => $this->summary($form) + ['submissions_count' => $form->submissions_count]);

        return Inertia::render('ServiceMonitoring/Forms/Index', [
            'forms' => $forms,
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', ServiceMonitoringForm::class);

        return Inertia::render('ServiceMonitoring/Forms/Create', [
            'organizationTypes' => $this->organizationTypes(),
            'limits' => $this->limits(),
        ]);
    }

    public function store(SaveServiceMonitoringFormRequest $request): RedirectResponse
    {
        $form = $this->forms->create($request->user(), $request->formAttributes());

        return to_route('service-monitoring.forms.show', $form)->with('success', __('service-monitoring.flash.form_saved'));
    }

    public function show(Request $request, ServiceMonitoringForm $form): Response
    {
        $this->authorize('view', $form);
        $user = $request->user();
        $form->load('organizationTypes:id,name_am,name_en');

        $versions = ServiceMonitoringForm::query()
            ->where('code', $form->code)
            ->withCount('submissions')
            ->orderByDesc('version')
            ->get(['id', 'code', 'version', 'status', 'published_at', 'updated_at']);

        return Inertia::render('ServiceMonitoring/Forms/Show', [
            'form' => $this->detail($form),
            'versions' => $versions->map(static fn (ServiceMonitoringForm $v): array => [
                'id' => $v->id,
                'version' => $v->version,
                'status' => $v->status->value,
                'published_at' => $v->published_at?->toIso8601String(),
                'updated_at' => $v->updated_at?->toIso8601String(),
                'submissions_count' => $v->submissions_count,
            ])->all(),
            'problems' => $form->status->isEditable() ? FormSchema::from($form->schema)->problems(forPublish: true) : [],
            'can' => [
                'update' => $user->can('update', $form),
                'publish' => $user->can('publish', $form),
                'archive' => $user->can('archive', $form),
                'delete' => $user->can('delete', $form) && $form->submissions()->doesntExist(),
                'newVersion' => ! $form->status->isEditable() && $versions->doesntContain(static fn (ServiceMonitoringForm $v): bool => $v->status === FormStatus::Draft),
            ],
        ]);
    }

    public function edit(ServiceMonitoringForm $form): Response
    {
        $this->authorize('update', $form);
        $form->load('organizationTypes:id,name_am,name_en');

        return Inertia::render('ServiceMonitoring/Forms/Edit', [
            'form' => $this->detail($form),
            'organizationTypes' => $this->organizationTypes(),
            'limits' => $this->limits(),
        ]);
    }

    public function update(SaveServiceMonitoringFormRequest $request, ServiceMonitoringForm $form): RedirectResponse
    {
        $this->forms->update($request->user(), $form, $request->formAttributes());

        return to_route('service-monitoring.forms.show', $form)->with('success', __('service-monitoring.flash.form_saved'));
    }

    public function publish(Request $request, ServiceMonitoringForm $form): RedirectResponse
    {
        $this->authorize('publish', $form);
        $this->forms->publish($request->user(), $form);

        return to_route('service-monitoring.forms.show', $form)->with('success', __('service-monitoring.flash.form_published'));
    }

    public function newVersion(Request $request, ServiceMonitoringForm $form): RedirectResponse
    {
        $this->authorize('create', ServiceMonitoringForm::class);
        $draft = $this->forms->newVersion($request->user(), $form);

        return to_route('service-monitoring.forms.edit', $draft)->with('success', __('service-monitoring.flash.form_version_created'));
    }

    public function duplicate(DuplicateServiceMonitoringFormRequest $request, ServiceMonitoringForm $form): RedirectResponse
    {
        $this->authorize('view', $form);
        $copy = $this->forms->duplicate($request->user(), $form, $request->validated('code'), $request->validated('name_am'));

        return to_route('service-monitoring.forms.edit', $copy)->with('success', __('service-monitoring.flash.form_duplicated'));
    }

    public function archive(Request $request, ServiceMonitoringForm $form): RedirectResponse
    {
        $this->authorize('archive', $form);
        $this->forms->archive($request->user(), $form);

        return to_route('service-monitoring.forms.show', $form)->with('success', __('service-monitoring.flash.form_archived'));
    }

    public function destroy(Request $request, ServiceMonitoringForm $form): RedirectResponse
    {
        $this->authorize('delete', $form);
        $this->forms->discard($request->user(), $form);

        return to_route('service-monitoring.forms.index')->with('success', __('service-monitoring.flash.form_discarded'));
    }

    /** @return array<string, mixed> */
    private function summary(ServiceMonitoringForm $form): array
    {
        return [
            'id' => $form->id,
            'code' => $form->code,
            'version' => $form->version,
            'name_am' => $form->name_am,
            'name_en' => $form->name_en,
            'status' => $form->status->value,
            'published_at' => $form->published_at?->toIso8601String(),
            'updated_at' => $form->updated_at?->toIso8601String(),
            'organization_types' => $form->organizationTypes->map(static fn (OrganizationType $t): array => ['id' => $t->id, 'name_am' => $t->name_am, 'name_en' => $t->name_en])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function detail(ServiceMonitoringForm $form): array
    {
        return $this->summary($form) + [
            'description' => $form->description,
            'schema' => $form->schema,
            'organization_type_ids' => $form->organizationTypes->pluck('id')->map(static fn ($id): string => (string) $id)->values()->all(),
        ];
    }

    /** @return list<array{id: string, name_am: string|null, name_en: string}> */
    private function organizationTypes(): array
    {
        return OrganizationType::query()->active()->orderBy('level_order')->orderBy('sort_order')->orderBy('name_en')
            ->get(['id', 'name_am', 'name_en'])
            ->map(static fn (OrganizationType $t): array => ['id' => $t->id, 'name_am' => $t->name_am, 'name_en' => $t->name_en])
            ->all();
    }

    /** @return array<string, int> */
    private function limits(): array
    {
        return [
            'sections' => FormSchema::MAX_SECTIONS,
            'blocks' => FormSchema::MAX_BLOCKS,
            'columns' => FormSchema::MAX_COLUMNS,
            'fixed_rows' => FormSchema::MAX_FIXED_ROWS,
            'dynamic_rows' => FormSchema::MAX_DYNAMIC_ROWS,
        ];
    }
}
