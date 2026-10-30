<?php

declare(strict_types=1);

namespace App\Http\Controllers\ServiceMonitoring;

use App\Enums\ServiceMonitoring\FormStatus;
use App\Enums\ServiceMonitoring\SubmissionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\ServiceMonitoring\ReviewServiceMonitoringReportRequest;
use App\Http\Requests\ServiceMonitoring\SaveServiceMonitoringReportRequest;
use App\Http\Requests\ServiceMonitoring\StartServiceMonitoringReportRequest;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\ServiceMonitoringForm;
use App\Models\ServiceMonitoringPeriod;
use App\Models\ServiceMonitoringSubmission;
use App\Services\OrganizationScope\OrganizationScopeService;
use App\Services\ServiceMonitoring\ServiceMonitoringSubmissionService;
use App\Services\ServiceMonitoring\ServiceMonitoringSummaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Institutions' reports and the consolidated summary (docs/service-monitoring.md §Reports). */
class ServiceMonitoringReportController extends Controller
{
    public function __construct(
        private readonly ServiceMonitoringSubmissionService $submissions,
        private readonly OrganizationScopeService $scope,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', ServiceMonitoringSubmission::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'period_id' => ['nullable', 'uuid'],
            'form_code' => ['nullable', 'string', 'max:64'],
            'status' => ['nullable', Rule::enum(SubmissionStatus::class)],
            'organization_type_id' => ['nullable', 'uuid'],
        ]);

        $reports = $this->scope->applyOrganizationScope(ServiceMonitoringSubmission::query(), $request->user())
            ->with(['organization:id,name_am,name_en,organization_type_id', 'organization.type:id,name_am,name_en', 'form:id,code,version,name_am,name_en', 'period:id,name_am,name_en,status'])
            ->when($filters['period_id'] ?? null, static fn ($q, string $id) => $q->where('period_id', $id))
            ->when($filters['form_code'] ?? null, static fn ($q, string $code) => $q->where('form_code', $code))
            ->when($filters['status'] ?? null, static fn ($q, string $status) => $q->where('status', $status))
            ->when($filters['organization_type_id'] ?? null, static fn ($q, string $id) => $q->whereHas('organization', static fn ($o) => $o->where('organization_type_id', $id)))
            ->when($filters['search'] ?? null, static function ($q, string $search): void {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';
                $q->whereHas('organization', static fn ($o) => $o->where(static fn ($n) => $n->where('name_am', 'like', $like)->orWhere('name_en', 'like', $like)));
            })
            ->latest('updated_at')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (ServiceMonitoringSubmission $s): array => $this->row($s));

        return Inertia::render('ServiceMonitoring/Reports/Index', [
            'reports' => $reports,
            'filters' => $filters,
            'periods' => $this->periods(),
            'openPeriods' => $this->periods(openOnly: true),
            'forms' => $this->publishedForms(),
            'organizationTypes' => $this->organizationTypes(),
            'can' => ['start' => $request->user()->can('create', ServiceMonitoringSubmission::class)],
        ]);
    }

    /** Institutions the user may report for on a form: assigned types, inside scope. */
    public function organizations(Request $request): JsonResponse
    {
        $this->authorize('create', ServiceMonitoringSubmission::class);
        $input = $request->validate([
            'form_id' => ['required', 'uuid'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $form = ServiceMonitoringForm::query()->published()->findOrFail($input['form_id']);
        $typeIds = $form->organizationTypes()->pluck('organization_types.id');

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], (string) ($input['search'] ?? '')).'%';
        $organizations = $this->scope->applyOrganizationScope(Organization::query(), $request->user(), 'id')
            ->whereIn('organization_type_id', $typeIds)
            ->where(static fn ($q) => $q->where('name_am', 'like', $like)->orWhere('name_en', 'like', $like)->orWhere('code', 'like', $like))
            ->orderBy('name_am')
            ->limit(30)
            ->get(['id', 'name_am', 'name_en', 'code']);

        return response()->json(['data' => $organizations]);
    }

    public function start(StartServiceMonitoringReportRequest $request): RedirectResponse
    {
        $organization = Organization::query()->findOrFail($request->validated('organization_id'));
        if (! $this->scope->canExercisePermission($request->user(), 'service_monitoring.reports.submit', (string) $organization->getKey())) {
            throw ValidationException::withMessages(['organization_id' => __('service-monitoring.errors.organization_out_of_scope')]);
        }

        $submission = $this->submissions->start(
            $request->user(),
            ServiceMonitoringForm::query()->findOrFail($request->validated('form_id')),
            ServiceMonitoringPeriod::query()->findOrFail($request->validated('period_id')),
            $organization,
        );

        return $request->user()->can('update', $submission)
            ? to_route('service-monitoring.reports.edit', $submission)
            : to_route('service-monitoring.reports.show', $submission);
    }

    public function show(Request $request, ServiceMonitoringSubmission $submission): Response
    {
        $this->authorize('view', $submission);

        return Inertia::render('ServiceMonitoring/Reports/Show', [
            'report' => $this->detail($submission),
            'can' => [
                'update' => $request->user()->can('update', $submission) && $submission->period->isOpen(),
                'review' => $request->user()->can('review', $submission)
                    && $submission->status === SubmissionStatus::Submitted
                    && (string) $submission->submitted_by !== (string) $request->user()->getKey(),
            ],
        ]);
    }

    public function edit(ServiceMonitoringSubmission $submission): Response
    {
        $this->authorize('update', $submission);

        return Inertia::render('ServiceMonitoring/Reports/Edit', ['report' => $this->detail($submission)]);
    }

    public function update(SaveServiceMonitoringReportRequest $request, ServiceMonitoringSubmission $submission): RedirectResponse
    {
        $submit = $request->boolean('submit');
        $this->submissions->save($request->user(), $submission, (array) $request->validated('data'), $submit, $request->file('attachment'));

        return $submit
            ? to_route('service-monitoring.reports.show', $submission)->with('success', __('service-monitoring.flash.report_submitted'))
            : to_route('service-monitoring.reports.edit', $submission)->with('success', __('service-monitoring.flash.report_saved'));
    }

    public function review(ReviewServiceMonitoringReportRequest $request, ServiceMonitoringSubmission $submission): RedirectResponse
    {
        $note = $request->validated('note');
        if ($request->validated('decision') === 'accept') {
            $this->submissions->accept($request->user(), $submission, $note);
            $message = __('service-monitoring.flash.report_accepted');
        } else {
            $this->submissions->returnForCorrection($request->user(), $submission, (string) $note);
            $message = __('service-monitoring.flash.report_returned');
        }

        return to_route('service-monitoring.reports.show', $submission)->with('success', $message);
    }

    public function attachment(ServiceMonitoringSubmission $submission): StreamedResponse
    {
        $this->authorize('view', $submission);
        $disk = Storage::disk(ServiceMonitoringSubmissionService::DISK);
        abort_unless($submission->attachment_path !== null && $disk->exists($submission->attachment_path), 404);

        return $disk->download($submission->attachment_path, $submission->attachment_name ?? 'attachment', [
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function summary(Request $request, ServiceMonitoringSummaryService $summaries): Response
    {
        $this->authorize('viewSummary', ServiceMonitoringSubmission::class);

        $filters = $request->validate([
            'form_code' => ['nullable', 'string', 'max:64'],
            'period_id' => ['nullable', 'uuid'],
            'organization_type_id' => ['nullable', 'uuid'],
            'accepted_only' => ['nullable', 'boolean'],
        ]);

        $forms = $this->publishedForms(includeRetired: true);
        $periods = $this->periods();
        $formCode = $filters['form_code'] ?? ($forms[0]['code'] ?? null);
        $periodId = $filters['period_id'] ?? ($periods[0]['id'] ?? null);

        $form = $formCode === null ? null : $this->layoutVersion($formCode);
        $period = $periodId === null ? null : ServiceMonitoringPeriod::query()->find($periodId);

        return Inertia::render('ServiceMonitoring/Summary/Index', [
            'filters' => ['form_code' => $formCode, 'period_id' => $periodId] + $filters,
            'forms' => $forms,
            'periods' => $periods,
            'organizationTypes' => $form === null ? [] : $form->organizationTypes()->get(['organization_types.id', 'name_am', 'name_en'])
                ->map(static fn (OrganizationType $t): array => ['id' => $t->id, 'name_am' => $t->name_am, 'name_en' => $t->name_en])->all(),
            'form' => $form === null ? null : ['id' => $form->id, 'code' => $form->code, 'version' => $form->version, 'name_am' => $form->name_am, 'name_en' => $form->name_en, 'schema' => $form->schema],
            'period' => $period === null ? null : ['id' => $period->id, 'name_am' => $period->name_am, 'name_en' => $period->name_en],
            'summary' => $form === null || $period === null ? null : $summaries->build(
                $request->user(),
                $form,
                $period,
                $filters['organization_type_id'] ?? null,
                (bool) ($filters['accepted_only'] ?? false),
            ),
        ]);
    }

    /** The version whose layout a summary is read through: the published one, else the latest retired one. */
    private function layoutVersion(string $code): ?ServiceMonitoringForm
    {
        return ServiceMonitoringForm::query()
            ->where('code', $code)
            ->where('status', '!=', FormStatus::Draft->value)
            ->orderByRaw('case when status = ? then 0 else 1 end', [FormStatus::Published->value])
            ->orderByDesc('version')
            ->first();
    }

    /** @return array<string, mixed> */
    private function row(ServiceMonitoringSubmission $submission): array
    {
        return [
            'id' => $submission->id,
            'status' => $submission->status->value,
            'form_code' => $submission->form_code,
            'form' => ['id' => $submission->form->id, 'version' => $submission->form->version, 'name_am' => $submission->form->name_am, 'name_en' => $submission->form->name_en],
            'period' => ['id' => $submission->period->id, 'name_am' => $submission->period->name_am, 'name_en' => $submission->period->name_en, 'status' => $submission->period->status->value],
            'organization' => [
                'id' => $submission->organization->id,
                'name_am' => $submission->organization->name_am,
                'name_en' => $submission->organization->name_en,
                'type' => $submission->organization->type ? ['name_am' => $submission->organization->type->name_am, 'name_en' => $submission->organization->type->name_en] : null,
            ],
            'submitted_at' => $submission->submitted_at?->toIso8601String(),
            'updated_at' => $submission->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function detail(ServiceMonitoringSubmission $submission): array
    {
        $submission->loadMissing(['form', 'period', 'organization.type:id,name_am,name_en', 'submitter:id,name', 'reviewer:id,name']);

        return $this->row($submission) + [
            'schema' => $submission->form->schema,
            'description' => $submission->form->description,
            'data' => $submission->data,
            'review_note' => $submission->review_note,
            'reviewed_at' => $submission->reviewed_at?->toIso8601String(),
            'submitter' => $submission->submitter?->name,
            'reviewer' => $submission->reviewer?->name,
            'attachment' => $submission->attachment_path === null ? null : [
                'name' => $submission->attachment_name,
                'url' => route('service-monitoring.reports.attachment', $submission),
            ],
            'period_open' => $submission->period->isOpen(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function periods(bool $openOnly = false): array
    {
        return ServiceMonitoringPeriod::query()
            ->when($openOnly, static fn ($q) => $q->open())
            ->orderByDesc('fiscal_year')
            ->orderByDesc('created_at')
            ->limit(40)
            ->get(['id', 'name_am', 'name_en', 'status', 'fiscal_year'])
            ->map(static fn (ServiceMonitoringPeriod $p): array => ['id' => $p->id, 'name_am' => $p->name_am, 'name_en' => $p->name_en, 'status' => $p->status->value])
            ->all();
    }

    /**
     * One entry per form code: the published version (or, with retired forms, the latest non-draft one).
     *
     * @return list<array<string, mixed>>
     */
    private function publishedForms(bool $includeRetired = false): array
    {
        $statuses = $includeRetired
            ? [FormStatus::Published->value, FormStatus::Superseded->value, FormStatus::Archived->value]
            : [FormStatus::Published->value];

        return ServiceMonitoringForm::query()
            ->whereIn('status', $statuses)
            ->orderBy('code')
            ->orderByRaw('case when status = ? then 0 else 1 end', [FormStatus::Published->value])
            ->orderByDesc('version')
            ->get(['id', 'code', 'version', 'name_am', 'name_en', 'status'])
            ->unique('code')
            ->map(static fn (ServiceMonitoringForm $f): array => ['id' => $f->id, 'code' => $f->code, 'version' => $f->version, 'name_am' => $f->name_am, 'name_en' => $f->name_en, 'status' => $f->status->value])
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function organizationTypes(): array
    {
        return OrganizationType::query()
            ->whereIn('id', static fn ($q) => $q->select('organization_type_id')->from('service_monitoring_form_organization_type'))
            ->orderBy('level_order')
            ->get(['id', 'name_am', 'name_en'])
            ->map(static fn (OrganizationType $t): array => ['id' => $t->id, 'name_am' => $t->name_am, 'name_en' => $t->name_en])
            ->all();
    }
}
