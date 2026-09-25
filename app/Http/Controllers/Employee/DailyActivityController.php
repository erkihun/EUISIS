<?php

declare(strict_types=1);

namespace App\Http\Controllers\Employee;

use App\Enums\DailyActivityCategory;
use App\Enums\DailyActivityProgressStatus;
use App\Enums\DailyActivityStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\DailyActivity\SaveDailyActivityRequest;
use App\Http\Requests\DailyActivity\UploadDailyActivityAttachmentRequest;
use App\Models\DailyActivityAttachment;
use App\Models\DailyActivityLog;
use App\Models\Employee;
use App\Models\EmployeePerformanceItem;
use App\Models\PositionService;
use App\Services\DailyActivity\DailyActivityCalendarService;
use App\Services\DailyActivity\DailyActivityPresenter;
use App\Services\DailyActivity\DailyActivityService;
use App\Services\DailyActivity\DailyActivitySettings;
use App\Services\DailyActivity\EmployeeWorkContextResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Employee self-service: My Work > Daily Activity / Calendar / History.
 *
 * The employee is ALWAYS the signed-in user's own record. No route here takes
 * an employee id, and log ids that do appear (attachments) are re-authorised
 * against ownership, so another employee's activity is unreachable.
 */
class DailyActivityController extends Controller
{
    public function __construct(
        private readonly DailyActivityService $service,
        private readonly DailyActivityCalendarService $calendar,
        private readonly DailyActivitySettings $settings,
        private readonly EmployeeWorkContextResolver $context,
        private readonly DailyActivityPresenter $presenter,
    ) {}

    public function entry(Request $request): Response
    {
        abort_unless($request->user()->can('daily_activities.view_own'), 403);

        $employee = $request->user()->employee;
        $today = $this->settings->today();

        if ($employee === null) {
            return Inertia::render('Employee/DailyActivity/Entry', ['employee' => null, 'today' => $today->toDateString()]);
        }

        $date = $this->dateParam($request->query('date')) ?? $today->copy();
        if ($date->toDateString() > $today->toDateString()) {
            $date = $today->copy();
        }

        $log = $this->service->findLog($employee, $date);
        $dayStatus = $this->calendar->dayStatus($employee, $date);
        $assignment = $this->context->assignmentOn($employee, $date);
        $positionId = $log?->position_id ?? $assignment?->position_id;
        $user = $request->user();

        $editable = $log === null
            ? $this->calendar->isRegistrableDay($dayStatus, $date->toDateString()) && $user->can('daily_activities.create')
            : $user->can('update', $log);

        $weekStart = $today->copy()->startOfWeek();

        return Inertia::render('Employee/DailyActivity/Entry', [
            'employee' => $this->presenter->employee($employee),
            'today' => $today->toDateString(),
            'date' => $date->toDateString(),
            'day_status' => $dayStatus->value,
            'placement' => $this->placement($log, $assignment),
            'log' => $log ? $this->presenter->detail($log) : null,
            'tasks' => $positionId === null ? [] : PositionService::query()
                ->where('position_id', $positionId)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name_en')
                ->get(['id', 'name_en', 'name_am'])
                ->map(fn (PositionService $service): array => ['id' => $service->id, 'name_en' => $service->name_en, 'name_am' => $service->name_am])
                ->all(),
            // EPMS: the employee's own KPIs agreed for this date (evidence link only).
            'kpis' => EmployeePerformanceItem::query()->where('is_current', true)
                ->whereHas('agreement', fn ($q) => $q->where('employee_id', $employee->id)
                    ->whereIn('status', ['AGREED', 'ACTIVE', 'UNDER_REVIEW'])
                    ->where('effective_from', '<=', $date->toDateString())->where('effective_to', '>=', $date->toDateString()))
                ->with('kpi:id,code,name_en,name_am,unit_of_measure')->get()
                ->map(fn (EmployeePerformanceItem $item): array => ['id' => $item->id, 'name_en' => $item->kpi->code.' — '.$item->kpi->name_en, 'name_am' => $item->kpi->code.' — '.($item->kpi->name_am ?? $item->kpi->name_en), 'unit' => $item->kpi->unit_of_measure])
                ->all(),
            'options' => [
                'categories' => DailyActivityCategory::values(),
                'progress_statuses' => DailyActivityProgressStatus::values(),
            ],
            'rules' => [
                ...$this->settings->forClient(),
                'would_be_late' => ($log?->first_submitted_at === null) && $this->service->isLate($date),
                'earliest_date' => $this->settings->allowBackdatedSubmission()
                    ? $today->copy()->subDays($this->settings->maxBackdateDays())->toDateString()
                    : $today->toDateString(),
            ],
            'can' => [
                'edit' => $editable && $this->settings->enabled(),
                'submit' => $log === null
                    ? $editable && $user->can('daily_activities.submit')
                    : $user->can('submit', $log),
                'upload' => $log !== null && $user->can('manageAttachments', $log) && $this->settings->evidenceAttachmentsEnabled(),
            ],
            'week' => $this->weekSummary($employee, $weekStart, $today),
            'today_status' => $this->calendar->dayStatus($employee, $today)->value,
            'recent' => DailyActivityLog::query()
                ->where('employee_id', $employee->id)
                ->withCount('items')
                ->orderByDesc('activity_date')
                ->limit(5)
                ->get(['id', 'activity_date', 'status', 'is_late'])
                ->map(fn (DailyActivityLog $recent): array => [
                    'date' => $recent->activityDateString(),
                    'status' => $recent->status->value,
                    'is_late' => $recent->is_late,
                    'items_count' => (int) $recent->items_count,
                ])->all(),
        ]);
    }

    public function save(SaveDailyActivityRequest $request, string $date): RedirectResponse
    {
        $employee = $this->ownEmployee($request);
        $activityDate = $this->dateParam($date) ?? abort(404);
        $submit = $request->input('action') === 'submit';

        if ($submit) {
            $existing = $this->service->findLog($employee, $activityDate);
            abort_unless(
                $existing === null
                    ? $request->user()->can('daily_activities.submit')
                    : ($existing->status->countsAsSubmitted() || $request->user()->can('submit', $existing)),
                403,
            );
        } else {
            abort_unless($request->user()->can('daily_activities.update_draft'), 403);
        }

        $log = $this->service->save($request->user(), $employee, $activityDate, $request->validated(), $submit);

        $message = match (true) {
            ! $submit => 'daily-activities.saved',
            $log->status === DailyActivityStatus::Resubmitted => 'daily-activities.resubmitted',
            default => 'daily-activities.submitted',
        };

        return redirect()
            ->route('employee.daily-activity.entry', ['date' => $activityDate->toDateString()])
            ->with('success', __($message));
    }

    public function calendar(Request $request): Response
    {
        abort_unless($request->user()->can('daily_activities.view_own'), 403);

        $employee = $request->user()->employee;
        $today = $this->settings->today();

        $from = $this->dateParam($request->query('from')) ?? $today->copy()->startOfMonth();
        $to = $this->dateParam($request->query('to')) ?? $from->copy()->endOfMonth()->startOfDay();
        if ($to->lessThan($from) || $from->diffInDays($to) > 42) {
            $to = $from->copy()->endOfMonth()->startOfDay();
        }

        return Inertia::render('Employee/DailyActivity/Calendar', [
            'has_employee' => $employee !== null,
            'today' => $today->toDateString(),
            // Keep the selected day stable when the client changes calendar systems.
            'anchor' => ($this->dateParam($request->query('anchor'))
                ?? $this->dateParam($request->query('from'))
                ?? $today)->toDateString(),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'days' => $employee ? $this->calendar->days($employee, $from, $to) : [],
            'summary' => $employee
                ? $this->summaryFrom($this->calendar->summaries(
                    collect([$employee]),
                    $from,
                    $to->toDateString() < $today->toDateString() ? $to : $today->copy(),
                )[$employee->id] ?? null)
                : null,
        ]);
    }

    public function history(Request $request): Response
    {
        abort_unless($request->user()->can('daily_activities.view_own'), 403);

        $employee = $request->user()->employee;
        $status = in_array($request->query('status'), DailyActivityStatus::values(), true) ? $request->query('status') : null;
        $from = $this->dateParam($request->query('date_from'));
        $to = $this->dateParam($request->query('date_to'));

        $logs = $employee === null ? null : DailyActivityLog::query()
            ->where('employee_id', $employee->id)
            ->when($status, fn ($query) => $query->where('status', $status))
            ->when($from, fn ($query) => $query->where('activity_date', '>=', $from->toDateString()))
            ->when($to, fn ($query) => $query->where('activity_date', '<=', $to->toDateString().' 23:59:59'))
            ->with(['items:id,daily_activity_log_id,title,progress_status,sort_order', ...DailyActivityPresenter::SUMMARY_WITH])
            ->withCount('items')
            ->orderByDesc('activity_date')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Employee/DailyActivity/History', [
            'has_employee' => $employee !== null,
            'logs' => $logs ? [
                'data' => collect($logs->items())->map(fn (DailyActivityLog $log): array => [
                    ...$this->presenter->summary($log),
                    'review_comment' => $log->review_comment,
                    'titles' => $log->items->pluck('title')->take(4)->all(),
                ])->all(),
                'meta' => [
                    'current_page' => $logs->currentPage(),
                    'last_page' => $logs->lastPage(),
                    'total' => $logs->total(),
                    'from' => $logs->firstItem(),
                    'to' => $logs->lastItem(),
                ],
                'links' => $logs->linkCollection()->toArray(),
            ] : null,
            'filters' => [
                'status' => $status,
                'date_from' => $from?->toDateString(),
                'date_to' => $to?->toDateString(),
            ],
            'statuses' => DailyActivityStatus::values(),
        ]);
    }

    public function uploadAttachment(UploadDailyActivityAttachmentRequest $request, DailyActivityLog $log): RedirectResponse
    {
        $this->authorizeOwnEvidence($request, $log);

        $this->service->addAttachment($request->user(), $log, $request->file('file'), $request->validated('item_id'));

        return back()->with('success', __('daily-activities.attachment_uploaded'));
    }

    public function destroyAttachment(Request $request, DailyActivityAttachment $attachment): RedirectResponse
    {
        $this->authorizeOwnEvidence($request, $attachment->log);

        $this->service->deleteAttachment($request->user(), $attachment);

        return back()->with('success', __('daily-activities.attachment_deleted'));
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Evidence belongs to the employee's own record. Gate::before lets Super
     * Admin pass any policy, so ownership is also checked here: nobody,
     * however privileged, adds or removes files on someone else's day.
     */
    private function authorizeOwnEvidence(Request $request, DailyActivityLog $log): void
    {
        abort_unless($request->user()->employee?->id === $log->employee_id, 403);
        $this->authorize('manageAttachments', $log);
    }

    private function ownEmployee(Request $request): Employee
    {
        $employee = $request->user()->employee;

        abort_if($employee === null, 403, __('daily-activities.no_employee'));

        return $employee;
    }

    private function dateParam(mixed $value): ?Carbon
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $value));

        return checkdate($month, $day, $year) ? Carbon::create($year, $month, $day)->startOfDay() : null;
    }

    /** @return array<string, mixed>|null */
    private function placement(?DailyActivityLog $log, $assignment): ?array
    {
        if ($log !== null) {
            $log->loadMissing(DailyActivityPresenter::SUMMARY_WITH);

            return [
                'organization' => $this->presenter->named($log->organization?->name_en, $log->organization?->name_am),
                'organization_unit' => $this->presenter->named($log->organizationUnit?->name_en, $log->organizationUnit?->name_am),
                'position' => $this->presenter->named($log->position?->title_en, $log->position?->title_am),
            ];
        }

        if ($assignment === null) {
            return null;
        }

        $assignment->loadMissing(['organization:id,name_en,name_am', 'organizationUnit:id,name_en,name_am', 'position:id,title_en,title_am']);

        return [
            'organization' => $this->presenter->named($assignment->organization?->name_en, $assignment->organization?->name_am),
            'organization_unit' => $this->presenter->named($assignment->organizationUnit?->name_en, $assignment->organizationUnit?->name_am),
            'position' => $this->presenter->named($assignment->position?->title_en, $assignment->position?->title_am),
        ];
    }

    /** @return array<string, int> */
    private function weekSummary(Employee $employee, Carbon $from, Carbon $to): array
    {
        return $this->summaryFrom($this->calendar->summaries(collect([$employee]), $from, $to)[$employee->id] ?? null) ?? [];
    }

    /**
     * @param  array<string, mixed>|null  $summary
     * @return array<string, int>|null
     */
    private function summaryFrom(?array $summary): ?array
    {
        if ($summary === null) {
            return null;
        }

        return [
            'required' => $summary['required'],
            'submitted' => $summary['submitted'],
            'approved' => $summary['approved'],
            'returned' => $summary['returned'],
            'missing' => $summary['missing'],
            'leave' => $summary['leave'],
            'holiday' => $summary['holiday'],
        ];
    }
}
