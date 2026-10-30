<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Modules\ServiceMonitoring\Application\Actions\SetNothingToReport;
use Modules\ServiceMonitoring\Application\Actions\StartSubmission;
use Modules\ServiceMonitoring\Application\Actions\SubmitSubmission;
use Modules\ServiceMonitoring\Application\Actions\UploadStampedCopy;
use Modules\ServiceMonitoring\Application\Services\AccountabilitySuggestionService;
use Modules\ServiceMonitoring\Application\Services\ConsolidationService;
use Modules\ServiceMonitoring\Application\Services\ExportList;
use Modules\ServiceMonitoring\Application\Services\PeriodDirectory;
use Modules\ServiceMonitoring\Application\Services\ServiceMonitoringAccess;
use Modules\ServiceMonitoring\Application\Services\SubmissionPresenter;
use Modules\ServiceMonitoring\Domain\Enums\ReportingLevel;
use Modules\ServiceMonitoring\Domain\Enums\SubmissionStatus;
use Modules\ServiceMonitoring\Domain\Models\Participant;
use Modules\ServiceMonitoring\Domain\Models\ReportingPeriod;
use Modules\ServiceMonitoring\Domain\Models\Submission;
use Modules\ServiceMonitoring\Http\Requests\SetNothingToReportRequest;
use Modules\ServiceMonitoring\Http\Requests\SubmitSubmissionRequest;
use Modules\ServiceMonitoring\Http\Requests\UploadFileRequest;
use Modules\ServiceMonitoring\Infrastructure\Exports\OfficialFormLayout;

/** Submission list, start, entry screen, read-only view, "የለም" flags, stamped copy and submit. */
final class SubmissionController extends Controller
{
    public function __construct(private readonly ServiceMonitoringAccess $access, private readonly SubmissionPresenter $presenter) {}

    public function index(Request $request, PeriodDirectory $periods): Response
    {
        $this->authorize('viewAny', ReportingPeriod::class);
        $user = $request->user();
        $filters = [
            'period_id' => self::uuidParam($request, 'period_id') ?? $periods->current()?->id,
            'level' => $request->string('level')->toString(),
            'status' => $request->string('status')->toString(),
            'sub_city_id' => self::uuidParam($request, 'sub_city_id') ?? '',
        ];

        $submissions = $this->access->scopeSubmissions(Submission::query(), $user)
            ->where('is_current', true)
            ->when($filters['period_id'], fn ($q, $id) => $q->where('period_id', $id))
            ->when(in_array($filters['level'], ReportingLevel::values(), true), fn ($q) => $q->where('level', $filters['level']))
            ->when(in_array($filters['status'], SubmissionStatus::values(), true), fn ($q) => $q->where('status', $filters['status']))
            ->when($filters['sub_city_id'] !== '', fn ($q) => $q->whereHas('participant', fn ($p) => $p->where('id', $filters['sub_city_id'])->orWhere('parent_participant_id', $filters['sub_city_id'])))
            ->with(['organization:id,name_en,name_am', 'period.fiscalYear'])
            ->orderBy('level')->latest('updated_at')
            ->paginate(50)->withQueryString();

        return Inertia::render('ServiceMonitoring/Submissions/Index', [
            'submissions' => $submissions->through(fn (Submission $s): array => [
                'id' => $s->id, 'status' => $s->status->value, 'level' => $s->level->value, 'form_type' => $s->form_type->value,
                'revision_no' => $s->revision_no, 'submitted_at' => $s->submitted_at?->toIso8601String(), 'submitted_late' => $s->submitted_late,
                'period_label' => $s->period->label(),
                'organization' => ['name_en' => $s->organization?->name_en, 'name_am' => $s->organization?->name_am],
                'can_edit' => $this->access->canEdit($user, $s),
            ]),
            'filters' => $filters,
            'years' => $periods->years(),
            'sub_cities' => $this->access->scopeParticipants(Participant::query(), $user)->where('level', ReportingLevel::SubCity->value)->with('organization:id,name_en,name_am')->get()
                ->map(fn (Participant $p): array => ['id' => $p->id, 'name_en' => $p->organization?->name_en, 'name_am' => $p->organization?->name_am])->all(),
        ]);
    }

    public function start(Request $request, ReportingPeriod $period, Participant $participant, StartSubmission $start): RedirectResponse
    {
        $this->authorize('start', $participant);
        $submission = $start->execute($period, $participant, $request->user());

        return redirect()->route('service-monitoring.submissions.edit', $submission);
    }

    public function edit(Request $request, Submission $submission, AccountabilitySuggestionService $suggestions): Response|RedirectResponse
    {
        $this->authorize('view', $submission);
        $user = $request->user();
        if (! $this->access->canEdit($user, $submission)) {
            return redirect()->route('service-monitoring.submissions.show', $submission);
        }
        $props = $this->presenter->present($submission, $user, true);
        $named = $props['section1']['named'];

        return Inertia::render('ServiceMonitoring/Submissions/Edit', $props + [
            'suggestions' => Inertia::defer(fn (): array => array_map(
                fn (array $s): array => $named ? $s : ['employee_name' => null] + $s,
                $suggestions->suggestionsFor($submission, $user),
            ), 'suggestions'),
        ]);
    }

    public function show(Request $request, Submission $submission, ConsolidationService $consolidation, ExportList $exports): Response
    {
        $this->authorize('view', $submission);
        $props = $this->presenter->present($submission, $request->user(), false);
        $locale = app()->getLocale();
        $period = $submission->period;
        $report = $consolidation->forSubmission($submission, $props['section1']['named']);

        return Inertia::render('ServiceMonitoring/Submissions/Show', $props + [
            'diff' => $this->presenter->diff($submission),
            'form' => [
                'form_type' => $submission->form_type->value,
                'sections' => OfficialFormLayout::sections($submission->form_type, $submission->form_version, (int) $period->fiscalYear->ec_year, $report, $props['section1']['named'], $locale),
            ],
            'years' => [['id' => $period->fiscal_year_id, 'ec_year' => $period->fiscalYear->ec_year]],
            'exports' => $exports->recentFor($request->user(), 'submission', $submission->id),
        ]);
    }

    /** Employees of the submission's institution, for the Section I picker (HR data, not accountability data). */
    public function employees(Request $request, Submission $submission): JsonResponse
    {
        $this->authorize('update', $submission);
        $search = trim($request->string('q')->toString());

        return response()->json(Employee::query()
            ->whereHas('currentAssignment', fn ($q) => $q->where('organization_id', $submission->organization_id))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->whereLike('full_name', "%{$search}%", caseSensitive: false)->orWhereLike('employee_number', "%{$search}%", caseSensitive: false)))
            ->orderBy('full_name')->limit(20)->get(['id', 'full_name', 'employee_number'])
            ->map(fn (Employee $e): array => ['id' => $e->id, 'full_name' => $e->full_name, 'employee_number' => $e->employee_number])->all());
    }

    public function nothing(SetNothingToReportRequest $request, Submission $submission, SetNothingToReport $set): RedirectResponse
    {
        $set->execute($submission, (int) $request->validated('section'), (bool) $request->validated('nothing'), $request->user());

        return back()->with('success', __('service-monitoring.flash.saved'));
    }

    public function stampedCopy(UploadFileRequest $request, Submission $submission, UploadStampedCopy $upload): RedirectResponse
    {
        $this->authorize('update', $submission);
        $upload->execute($submission, $request->file('file'), $request->user());

        return back()->with('success', __('service-monitoring.flash.uploaded'));
    }

    public function submit(SubmitSubmissionRequest $request, Submission $submission, SubmitSubmission $submit): RedirectResponse
    {
        $submit->execute($submission, $request->user(), (bool) $request->validated('declaration'));

        return redirect()->route('service-monitoring.submissions.show', $submission)->with('success', __('service-monitoring.flash.submitted'));
    }

    /** A UUID query parameter, or null when absent or malformed (PostgreSQL rejects a non-UUID on a uuid column). */
    private static function uuidParam(Request $request, string $key): ?string
    {
        $value = $request->string($key)->toString();

        return Str::isUuid($value) ? $value : null;
    }
}
