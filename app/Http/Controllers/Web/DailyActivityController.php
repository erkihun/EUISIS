<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\DailyActivityDayStatus;
use App\Enums\DailyActivityStatus;
use App\Exports\DailyActivity\DailyActivityReportExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\DailyActivity\ReopenDailyActivityRequest;
use App\Http\Requests\DailyActivity\ReviewDailyActivityRequest;
use App\Models\DailyActivityLog;
use App\Services\Calendar\LocalizedDateService;
use App\Services\DailyActivity\DailyActivityCalendarService;
use App\Services\DailyActivity\DailyActivityPresenter;
use App\Services\DailyActivity\DailyActivityQueryService;
use App\Services\DailyActivity\DailyActivityReportService;
use App\Services\DailyActivity\DailyActivityReviewerResolver;
use App\Services\DailyActivity\DailyActivityService;
use App\Services\DailyActivity\DailyActivitySettings;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Management side of the Daily Activity Register.
 *
 * Every list is built by DailyActivityQueryService from the actor's coverage
 * (organization scope and/or reviewer assignments). Request filters can only
 * narrow that coverage, never widen it, and every single-record action is
 * re-authorised against the record by DailyActivityLogPolicy.
 */
class DailyActivityController extends Controller
{
    private const FILTER_KEYS = ['date_from', 'date_to', 'organization_id', 'organization_unit_id', 'position_id', 'status', 'late', 'position_service_id', 'search'];

    public function __construct(
        private readonly DailyActivityQueryService $queries,
        private readonly DailyActivityCalendarService $calendar,
        private readonly DailyActivityReportService $reports,
        private readonly DailyActivityPresenter $presenter,
        private readonly DailyActivityService $service,
        private readonly DailyActivitySettings $settings,
        private readonly DailyActivityReviewerResolver $reviewers,
    ) {}

    public function dashboard(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->can('daily_activities.view_reports') || $user->can('daily_activities.view_team') || $user->can('daily_activities.view_scoped'), 403);

        $coverage = $this->queries->reportCoverage($user)->merge($this->queries->visibleCoverage($user));
        $today = $this->settings->today();
        $weekStart = $today->copy()->subDays(6);
        $filters = $this->filters($request, ['organization_id', 'organization_unit_id']);

        $employees = $this->queries->employeesInCoverage($coverage, $weekStart, $today, $filters);
        $rows = $this->calendar->rows($employees, $weekStart, $today, $this->queries->assignmentPredicate($coverage, $filters));

        $days = [];
        foreach ($rows as $row) {
            $day = &$days[$row['date']];
            $day ??= ['date' => $row['date'], 'expected' => 0, 'submitted' => 0, 'missing' => 0, 'late' => 0, 'leave' => 0];
            if ($row['status'] === DailyActivityDayStatus::Leave) {
                $day['leave']++;
            }
            if ($row['status']->isRequiredDay()) {
                $day['expected']++;
                if (in_array($row['status'], [DailyActivityDayStatus::Submitted, DailyActivityDayStatus::Approved], true)) {
                    $day['submitted']++;
                }
                // Today is still open, so an unsubmitted day counts as
                // "not yet submitted" rather than missing.
                if ($row['missing'] || ($row['date'] === $today->toDateString() && in_array($row['status'], [DailyActivityDayStatus::Required, DailyActivityDayStatus::Draft], true))) {
                    $day['missing']++;
                }
            }
            if ($row['log']?->is_late && $row['log']->status->countsAsSubmitted()) {
                $day['late']++;
            }
            unset($day);
        }
        ksort($days);

        $visibleLogs = fn () => $this->queries->logs($coverage, $filters);
        $reviewQueue = $this->reviewQueueQuery($request);

        return Inertia::render('DailyActivities/Dashboard', [
            'today' => $today->toDateString(),
            'todayFigures' => $days[$today->toDateString()] ?? ['date' => $today->toDateString(), 'expected' => 0, 'submitted' => 0, 'missing' => 0, 'late' => 0, 'leave' => 0],
            'days' => array_values(array_reverse($days)),
            'pendingReview' => $reviewQueue?->count() ?? 0,
            'returned' => (clone $visibleLogs())->where('status', DailyActivityStatus::ReturnedForCorrection->value)->count(),
            'awaitingReviewList' => $reviewQueue
                ? $reviewQueue->with(DailyActivityPresenter::SUMMARY_WITH)->withCount('items')->orderBy('activity_date')->limit(8)->get()
                    ->map(fn (DailyActivityLog $log): array => $this->presenter->summary($log))->all()
                : [],
            'reviewRequired' => $this->settings->managerReviewRequired(),
            'filters' => $filters,
            'options' => $this->queries->filterOptions($coverage, $filters['organization_id'] ?? null),
            'can' => $this->abilities($request),
        ]);
    }

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->can('daily_activities.view_scoped') || $user->can('daily_activities.view_team'), 403);

        $coverage = $this->queries->visibleCoverage($user);
        $today = $this->settings->today();
        $filters = $this->filters($request, self::FILTER_KEYS);
        [$from, $to] = $this->queries->range($filters['date_from'] ?? null, $filters['date_to'] ?? null, $today, 6);
        $filters = [...$filters, 'date_from' => $from->toDateString(), 'date_to' => $to->toDateString()];

        $logs = $this->queries->logs($coverage, $filters)
            ->with(DailyActivityPresenter::SUMMARY_WITH)
            ->withCount('items')
            ->orderByDesc('activity_date')
            ->orderBy('employee_id')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return Inertia::render('DailyActivities/Index', [
            'logs' => $this->paginated($logs, fn (DailyActivityLog $log): array => $this->presenter->summary($log)),
            'filters' => $filters,
            'options' => [...$this->queries->filterOptions($coverage, $filters['organization_id'] ?? null), 'statuses' => DailyActivityStatus::values()],
            'can' => $this->abilities($request),
        ]);
    }

    public function missing(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->can('daily_activities.view_reports') || $user->can('daily_activities.view_scoped') || $user->can('daily_activities.view_team'), 403);

        $coverage = $this->queries->reportCoverage($user)->merge($this->queries->visibleCoverage($user));
        $filters = $this->filters($request, ['date_from', 'date_to', 'organization_id', 'organization_unit_id', 'position_id', 'search']);
        $report = $this->reports->build('missing', $coverage, $filters, DailyActivityReportService::EXPORT_LIMIT);

        $page = max(1, (int) $request->integer('page', 1));
        $perPage = $this->perPage($request);
        $paginator = new LengthAwarePaginator(
            array_slice($report['rows'], ($page - 1) * $perPage, $perPage),
            count($report['rows']),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        return Inertia::render('DailyActivities/Missing', [
            'rows' => [
                'data' => $paginator->items(),
                'meta' => ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'total' => $paginator->total(), 'from' => $paginator->firstItem(), 'to' => $paginator->lastItem()],
                'links' => $paginator->linkCollection()->toArray(),
            ],
            'filters' => [...$filters, 'date_from' => $report['from'], 'date_to' => $report['to']],
            'options' => $this->queries->filterOptions($coverage, $filters['organization_id'] ?? null),
            'can' => $this->abilities($request),
        ]);
    }

    public function reviewQueue(Request $request): Response
    {
        abort_unless($request->user()->can('daily_activities.review'), 403);

        $filters = $this->filters($request, ['date_from', 'date_to', 'organization_unit_id', 'search', 'late']);
        $query = $this->reviewQueueQuery($request);

        $logs = $query?->when(! empty($filters['date_from']), fn ($q) => $q->where('activity_date', '>=', $filters['date_from']))
            ->when(! empty($filters['date_to']), fn ($q) => $q->where('activity_date', '<=', $filters['date_to'].' 23:59:59'))
            ->when(! empty($filters['organization_unit_id']), fn ($q) => $q->where('organization_unit_id', $filters['organization_unit_id']))
            ->when(($filters['late'] ?? '') !== '', fn ($q) => $q->where('is_late', filter_var($filters['late'], FILTER_VALIDATE_BOOLEAN)))
            ->when(! empty($filters['search']), fn ($q) => $q->whereHas('employee', fn ($e) => $e
                ->where('full_name', 'like', '%'.$filters['search'].'%')
                ->orWhere('employee_number', 'like', '%'.$filters['search'].'%')))
            ->with(DailyActivityPresenter::SUMMARY_WITH)
            ->withCount('items')
            ->orderBy('activity_date')
            ->orderBy('submitted_at')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return Inertia::render('DailyActivities/ReviewQueue', [
            'logs' => $logs ? $this->paginated($logs, fn (DailyActivityLog $log): array => $this->presenter->summary($log)) : null,
            'filters' => $filters,
            'hasAssignment' => $this->reviewers->hasAnyAssignment($request->user()),
            'reviewRequired' => $this->settings->managerReviewRequired(),
            'options' => $this->queries->filterOptions($this->reviewers->coverage($request->user()), null),
            'can' => $this->abilities($request),
        ]);
    }

    public function show(Request $request, DailyActivityLog $log): Response
    {
        $this->authorize('view', $log);
        $user = $request->user();
        $ownLog = $this->reviewers->isOwnLog($user, $log);

        return Inertia::render('DailyActivities/Show', [
            'log' => $this->presenter->detail($log),
            'can' => [
                // Gate::before lets Super Admin pass any policy; reviewing
                // one's own work is refused here regardless.
                'approve' => ! $ownLog && $user->can('approve', $log),
                'return' => ! $ownLog && $user->can('returnForCorrection', $log),
                'reopen' => ! $ownLog && $user->can('reopen', $log),
            ],
        ]);
    }

    public function approve(ReviewDailyActivityRequest $request, DailyActivityLog $log): RedirectResponse
    {
        $this->authorizeReviewAction($request, 'approve', $log);

        $this->service->approve($request->user(), $log, $request->validated('comment'), $request->itemNotes());

        return redirect()->route('daily-activities.review-queue')->with('success', __('daily-activities.approved'));
    }

    public function returnForCorrection(ReviewDailyActivityRequest $request, DailyActivityLog $log): RedirectResponse
    {
        $this->authorizeReviewAction($request, 'returnForCorrection', $log);

        $this->service->returnForCorrection($request->user(), $log, (string) $request->validated('comment'), $request->itemNotes());

        return redirect()->route('daily-activities.review-queue')->with('success', __('daily-activities.returned'));
    }

    public function reopen(ReopenDailyActivityRequest $request, DailyActivityLog $log): RedirectResponse
    {
        $this->authorizeReviewAction($request, 'reopen', $log);

        $this->service->reopen($request->user(), $log, (string) $request->validated('reason'));

        return redirect()->route('daily-activities.show', $log)->with('success', __('daily-activities.reopened'));
    }

    public function reports(Request $request): Response
    {
        abort_unless($request->user()->can('daily_activities.view_reports'), 403);

        $type = in_array($request->query('type'), DailyActivityReportService::TYPES, true) ? $request->query('type') : 'daily_submission';
        // Report rows carry localized unit / position / task names.
        app()->setLocale($this->readerLocale($request));
        $coverage = $this->queries->reportCoverage($request->user());
        $filters = $this->filters($request, [...self::FILTER_KEYS, 'date', 'month']);
        $report = $this->reports->build($type, $coverage, $filters);

        return Inertia::render('DailyActivities/Reports', [
            'type' => $type,
            'types' => DailyActivityReportService::TYPES,
            'report' => $report,
            'filters' => [...$filters, 'date_from' => $report['from'], 'date_to' => $report['to']],
            'options' => [...$this->queries->filterOptions($coverage, $filters['organization_id'] ?? null), 'statuses' => DailyActivityStatus::values()],
            'can' => $this->abilities($request),
        ]);
    }

    public function export(Request $request, WriteAuditLogAction $audit, LocalizedDateService $dates): HttpResponse
    {
        $user = $request->user();
        abort_unless($user->can('daily_activities.view_reports') && $user->can('daily_activities.export'), 403);

        $type = in_array($request->query('type'), DailyActivityReportService::TYPES, true) ? $request->query('type') : abort(404);
        $format = in_array($request->query('format'), ['csv', 'xlsx', 'pdf'], true) ? $request->query('format') : 'xlsx';
        // Rendered server-side, so the reader's language and calendar must be
        // applied before any label, name or date is formatted.
        app()->setLocale($this->readerLocale($request));
        $filters = $this->filters($request, [...self::FILTER_KEYS, 'date', 'month']);

        $report = $this->reports->build($type, $this->queries->reportCoverage($user), $filters, DailyActivityReportService::EXPORT_LIMIT);

        $audit->execute(
            AuditEventType::ExportPerformed,
            $user,
            null,
            null,
            null,
            ['module' => 'daily_activity', 'report' => $type, 'format' => $format, 'rows' => count($report['rows']), 'from' => $report['from'], 'to' => $report['to']],
            request: $request,
        );

        $export = new DailyActivityReportExport($report, $dates);
        $filename = 'daily-activity-'.str_replace('_', '-', $type).'-'.$report['from'].'-to-'.$report['to'];

        return match ($format) {
            'csv' => Excel::download($export, $filename.'.csv', \Maatwebsite\Excel\Excel::CSV),
            'xlsx' => Excel::download($export, $filename.'.xlsx'),
            'pdf' => Pdf::loadView('daily-activities.report-pdf', [
                'title' => __("daily-activities.reports.{$type}"),
                'headings' => $export->headings(),
                'rows' => $export->array(),
                'period' => __('daily-activities.reports.period', ['from' => $dates->displayDate($report['from']), 'to' => $dates->displayDate($report['to'])]),
                'generated' => __('daily-activities.reports.generated', ['at' => $dates->displayDateTime(now()), 'user' => $user->name]),
                'note' => __('daily-activities.reports.note'),
                'truncated' => $report['truncated'],
            ])->setPaper('a4', 'landscape')->download($filename.'.pdf'),
        };
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Logs waiting for THIS user's review. Review authority comes only from
     * reviewer assignments, never from organization-scope oversight.
     *
     * @return Builder<DailyActivityLog>|null
     */
    private function reviewQueueQuery(Request $request): ?Builder
    {
        $user = $request->user();

        if (! $user->can('daily_activities.review') || ! $this->settings->managerReviewRequired()) {
            return null;
        }

        return $this->reviewers->constrainReviewable(DailyActivityLog::query(), $user)
            ->whereIn('status', DailyActivityStatus::awaitingReviewValues());
    }

    private function readerLocale(Request $request): string
    {
        foreach ([$request->query('locale'), session('locale')] as $candidate) {
            if (is_string($candidate) && in_array($candidate, ['en', 'am'], true)) {
                return $candidate;
            }
        }

        return in_array(app()->getLocale(), ['en', 'am'], true) ? app()->getLocale() : 'en';
    }

    private function authorizeReviewAction(Request $request, string $ability, DailyActivityLog $log): void
    {
        abort_if($this->reviewers->isOwnLog($request->user(), $log), 403);
        $this->authorize($ability, $log);
    }

    /**
     * @param  array<int, string>  $keys
     * @return array<string, string>
     */
    private function filters(Request $request, array $keys): array
    {
        $filters = [];

        foreach ($keys as $key) {
            $value = $request->query($key);
            if (is_string($value) && trim($value) !== '') {
                $filters[$key] = mb_substr(trim($value), 0, 100);
            }
        }

        return $filters;
    }

    private function perPage(Request $request): int
    {
        $perPage = (int) $request->integer('per_page', 25);

        return in_array($perPage, [10, 25, 50, 100], true) ? $perPage : 25;
    }

    /** @return array<string, mixed> */
    private function paginated(\Illuminate\Contracts\Pagination\LengthAwarePaginator $paginator, callable $map): array
    {
        return [
            'data' => collect($paginator->items())->map($map)->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
                'per_page' => $paginator->perPage(),
            ],
            'links' => $paginator->linkCollection()->toArray(),
        ];
    }

    /** @return array<string, bool> */
    private function abilities(Request $request): array
    {
        $user = $request->user();

        return [
            'viewRegister' => $user->can('daily_activities.view_scoped') || $user->can('daily_activities.view_team'),
            'review' => $user->can('daily_activities.review'),
            'viewReports' => $user->can('daily_activities.view_reports'),
            'export' => $user->can('daily_activities.export'),
            'manageSettings' => $user->can('daily_activity_settings.view') || $user->can('daily_activities.manage_reviewers'),
        ];
    }
}
