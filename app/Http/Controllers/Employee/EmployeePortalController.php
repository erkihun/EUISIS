<?php

declare(strict_types=1);

namespace App\Http\Controllers\Employee;

use App\Actions\Transfers\SubmitTransferApplicationAction;
use App\Enums\CardStatus;
use App\Enums\DailyActivityStatus;
use App\Enums\EntitlementStatus;
use App\Enums\Performance\AgreementStatus;
use App\Enums\Performance\AppealStatus;
use App\Enums\Performance\KpiAggregation;
use App\Enums\Performance\KpiHealth;
use App\Enums\Performance\ResultStatus;
use App\Enums\Performance\ReviewStatus;
use App\Enums\Performance\ReviewType;
use App\Enums\TransferAnnouncementStatus;
use App\Enums\TransferApplicationStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Public\PublicTransferAnnouncementController;
use App\Http\Requests\Transfers\PublicStoreTransferApplicationRequest;
use App\Http\Resources\EmployeeSelfServiceResource;
use App\Models\CafeteriaTransaction;
use App\Models\CafeteriaTransactionConsumedDay;
use App\Models\DailyActivityLog;
use App\Models\Employee;
use App\Models\EmployeeCorrectionRequest;
use App\Models\EmployeePerformanceAgreement;
use App\Models\IdCard;
use App\Models\PerformanceAppeal;
use App\Models\ServiceTransaction;
use App\Models\TransferAnnouncement;
use App\Models\TransferApplication;
use App\Models\TransportPass;
use App\Models\TransportTransaction;
use App\Models\User;
use App\Services\Cafeteria\CafeteriaAvailableSubsidyService;
use App\Services\Cafeteria\CafeteriaLedgerService;
use App\Services\Cafeteria\CafeteriaSubsidyRuleResolver;
use App\Services\Cafeteria\WorkingDayCalendarService;
use App\Services\DailyActivity\DailyActivityCalendarService;
use App\Services\DailyActivity\DailyActivitySettings;
use App\Services\DailyActivity\WorkCalendarService;
use App\Services\Performance\EmployeeScoreCalculator;
use App\Services\Performance\EpmsSettings;
use App\Services\Performance\PerformanceAggregationService;
use App\Services\Performance\PerformanceReviewService;
use App\Support\NotificationPresenter;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class EmployeePortalController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $employee = $user->employee;

        if ($employee === null) {
            return Inertia::render('Employee/Portal', [
                'employee' => null,
                'assignment' => null,
                'id_card' => null,
                'entitlements' => [],
                'transfer_apps' => [],
                'open_announcements' => [],
                'daily_activity' => null,
                'performance' => null,
                'notifications' => ['unread' => 0, 'latest' => []],
                'pending_requests' => 0,
                'holidays' => [],
                'requests' => [],
            ]);
        }

        $employee->loadMissing([
            'currentAssignment.organization',
            'currentAssignment.position',
            'currentAssignment.organizationUnit',
        ]);

        $assignment = $employee->currentAssignment;

        // ── ID Card ───────────────────────────────────────────────────────────
        $idCard = IdCard::query()
            ->where('employee_id', $employee->id)
            ->where('is_current', true)
            ->first();

        $idCardData = null;
        if ($idCard) {
            $idCardData = [
                'card_number' => $idCard->card_number,
                'status' => $idCard->status?->value,
                'expires_at' => $idCard->expires_at?->toDateString(),
                'activated_at' => $idCard->activated_at?->toDateString(),
                'is_active' => $idCard->status === CardStatus::Active,
                // A reprint flag never invalidates the card; it only asks for a new print.
                'reprint_required' => (bool) $idCard->reprint_required,
                'issued_at' => $idCard->issued_at?->toDateString(),
            ];
        }

        // ── Entitlements ──────────────────────────────────────────────────────
        $entitlements = $employee->entitlements()
            ->with('serviceType:id,name_en,name_am,code')
            ->where('status', 'active')
            ->whereDate('effective_from', '<=', now())
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', now()))
            ->get()
            ->map(fn ($e) => [
                'id' => $e->id,
                'service' => $e->serviceType?->name_en,
                'service_am' => $e->serviceType?->name_am,
                'service_code' => $e->serviceType?->code,
                'quota_limit' => $e->quota_limit,
                'quota_used' => $e->quota_used,
                'effective_from' => $e->effective_from?->toDateString(),
                'effective_to' => $e->effective_to?->toDateString(),
            ])
            ->all();

        // ── Transfer applications ─────────────────────────────────────────────
        $appliedIds = TransferApplication::query()
            ->where('employee_id', $employee->id)
            ->whereNotIn('status', [
                TransferApplicationStatus::Withdrawn->value,
                TransferApplicationStatus::Cancelled->value,
            ])
            ->pluck('announcement_id')
            ->all();

        $transferApps = TransferApplication::query()
            ->where('employee_id', $employee->id)
            ->with(['announcement.organization', 'announcement.position'])
            ->orderByDesc('submitted_at')
            ->limit(5)
            ->get()
            ->map(fn (TransferApplication $app) => [
                'id' => $app->id,
                'status' => $app->status?->value,
                'status_label' => $app->status?->label(),
                'submitted_at' => $app->submitted_at?->toDateString(),
                'organization' => $app->announcement?->organization?->name_en,
                'organization_am' => $app->announcement?->organization?->name_am,
                'position' => $app->announcement?->position?->title_en,
                'position_am' => $app->announcement?->position?->title_am,
                'announcement_id' => $app->announcement_id,
                'closing_date' => $app->announcement?->closing_date?->toDateString(),
            ])
            ->all();

        // ── Open announcements ────────────────────────────────────────────────
        $openAnnouncements = TransferAnnouncement::query()
            ->where('status', TransferAnnouncementStatus::Published)
            ->where('opening_date', '<=', now())
            ->where('closing_date', '>=', now())
            ->with(['organization', 'position'])
            ->when(count($appliedIds) > 0, fn ($q) => $q->whereNotIn('id', $appliedIds))
            ->orderByDesc('published_at')
            ->limit(3)
            ->get()
            ->map(fn (TransferAnnouncement $a) => [
                'id' => $a->id,
                'organization' => $a->organization?->name_en,
                'organization_am' => $a->organization?->name_am,
                'position' => $a->position?->title_en,
                'position_am' => $a->position?->title_am,
                'grade_level' => $a->grade_level,
                'vacancies' => $a->totalVacancyCount(),
                'closing_date' => $a->closing_date?->toDateString(),
            ])
            ->all();

        return Inertia::render('Employee/Portal', [
            'portal_labels' => ['en' => trans('employee-portal', [], 'en'), 'am' => trans('employee-portal', [], 'am')],
            'employee' => (new EmployeeSelfServiceResource($employee))->resolve($request),
            'assignment' => $assignment ? [
                'organization' => $assignment->organization?->name_en,
                'organization_am' => $assignment->organization?->name_am,
                'organization_unit' => $assignment->organizationUnit?->name_en,
                'organization_unit_am' => $assignment->organizationUnit?->name_am,
                'position' => $assignment->position?->title_en,
                'position_am' => $assignment->position?->title_am,
                'grade_level' => $assignment->position?->grade_level,
                'effective_from' => $assignment->effective_from?->toDateString(),
                'status' => $assignment->assignment_status?->value,
            ] : null,
            'id_card' => $idCardData,
            'entitlements' => $entitlements,
            'transfer_apps' => $transferApps,
            'open_announcements' => $openAnnouncements,
            'daily_activity' => $this->dailyActivitySummary($user, $employee),
            'performance' => $this->performanceSummary($user, $employee),
            'notifications' => [
                'unread' => $user->unreadNotifications()->count(),
                'latest' => $user->notifications()->latest()->limit(3)->get()
                    ->map(fn ($notification) => NotificationPresenter::present($notification, NotificationPresenter::locale($request)))
                    ->all(),
            ],
            'holidays' => $this->upcomingHolidays(),
            'requests' => EmployeeCorrectionRequest::query()
                ->where('employee_id', $employee->id)
                ->latest()
                ->limit(3)
                ->get()
                // Field key and status only: never the requested value.
                ->map(fn (EmployeeCorrectionRequest $request) => [
                    'id' => $request->id,
                    'field' => $request->field,
                    'status' => $request->status,
                    'created_at' => $request->created_at?->toDateString(),
                ])
                ->all(),
            'pending_requests' => EmployeeCorrectionRequest::query()
                ->where('employee_id', $employee->id)
                ->where('status', 'pending')
                ->count(),
        ]);
    }

    /**
     * The next public holidays from the shared holiday calendar (recurring
     * Gregorian and Ethiopian holidays included), for planning ahead.
     *
     * @return array<int, array{date: string, name_en: string, name_am: ?string}>
     */
    private function upcomingHolidays(): array
    {
        try {
            $today = app(DailyActivitySettings::class)->today();
            $holidays = app(WorkCalendarService::class)->holidaysBetween($today, $today->copy()->addDays(120));

            return collect($holidays)
                ->map(fn (array $holiday, string $date): array => ['date' => $date, ...$holiday])
                ->values()
                ->take(4)
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Daily activity figures for the dashboard: today's state, this week's
     * days and the counts that call for action. Null when the account may not
     * use Daily Activity, so the dashboard simply leaves the panel out.
     *
     * @return array<string, mixed>|null
     */
    private function dailyActivitySummary(User $user, Employee $employee): ?array
    {
        if (! $user->can('daily_activities.view_own')) {
            return null;
        }

        try {
            $settings = app(DailyActivitySettings::class);
            $calendar = app(DailyActivityCalendarService::class);
            $today = $settings->today();
            $weekStart = $today->copy()->startOfWeek();
            $weekEnd = $weekStart->copy()->addDays(6);

            $summary = $calendar->summaries(collect([$employee]), $weekStart, $today)[$employee->id] ?? [];

            return [
                'today' => $today->toDateString(),
                'today_status' => $calendar->dayStatus($employee, $today)->value,
                'week' => array_map(fn (array $day): array => [
                    'date' => $day['date'],
                    'status' => $day['status'],
                    'is_late' => $day['is_late'],
                    'items_count' => $day['items_count'],
                ], $calendar->days($employee, $weekStart, $weekEnd)),
                'required' => (int) ($summary['required'] ?? 0),
                'submitted' => (int) ($summary['submitted'] ?? 0),
                'missing' => (int) ($summary['missing'] ?? 0),
                // All returned days, not just this week: each needs correcting.
                'recent' => DailyActivityLog::query()
                    ->where('employee_id', $employee->id)
                    ->withCount('items')
                    ->orderByDesc('activity_date')
                    ->limit(5)
                    ->get(['id', 'activity_date', 'status', 'is_late'])
                    ->map(fn (DailyActivityLog $log): array => [
                        'date' => $log->activityDateString(),
                        'status' => $log->status->value,
                        'is_late' => $log->is_late,
                        'items_count' => (int) $log->items_count,
                    ])
                    ->all(),
                'returned' => DailyActivityLog::query()
                    ->where('employee_id', $employee->id)
                    ->where('status', DailyActivityStatus::ReturnedForCorrection->value)
                    ->count(),
            ];
        } catch (Throwable $exception) {
            Log::warning('Employee dashboard daily activity panel unavailable', [
                'employee_id' => $employee->id,
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * My Performance at a glance: the current agreement, per-KPI progress from
     * measured actuals (never a preliminary total score), the result once it
     * is released, and the steps waiting for the employee. Null when EPMS is
     * off or the account may not use it.
     *
     * @return array<string, mixed>|null
     */
    private function performanceSummary(User $user, Employee $employee): ?array
    {
        $settings = app(EpmsSettings::class);
        if (! $settings->enabled() || ! $user->can('employee_performance_agreements.view_own')) {
            return null;
        }

        try {
            // The newest agreement that is still running, else the newest one.
            $agreements = EmployeePerformanceAgreement::query()->where('employee_id', $employee->id)
                ->with('cycle')->orderByDesc('effective_from')->limit(10)->get();
            $agreement = $agreements->first(fn (EmployeePerformanceAgreement $a) => $a->status !== AgreementStatus::Closed) ?? $agreements->first();
            if ($agreement === null) {
                return ['agreement' => null, 'kpis' => [], 'counts' => null, 'result' => null, 'actions' => []];
            }

            $aggregation = app(PerformanceAggregationService::class);
            $asOf = now()->startOfDay();
            $kpis = collect(app(EmployeeScoreCalculator::class)->trace($agreement)['items'])
                ->map(fn (array $item): array => [
                    'code' => $item['kpi_code'],
                    'name_en' => $item['kpi_name_en'],
                    'name_am' => $item['kpi_name_am'],
                    'weight' => $item['weight'],
                    'achievement' => $item['achievement_status'] === 'OK' ? $item['achievement'] : null,
                    'health' => $aggregation->healthFor(
                        $item['achievement_status'] === 'OK' ? $item['achievement'] : null,
                        KpiAggregation::from($item['aggregation']['method']),
                        $agreement->effective_from, $agreement->effective_to, $asOf,
                    )->value,
                ])
                ->sortByDesc(fn (array $kpi) => (float) $kpi['weight'])->values();

            $actions = [];
            if ($agreement->status === AgreementStatus::PendingEmployeeReview) {
                $actions[] = ['key' => 'acknowledge'];
            }
            $reviews = app(PerformanceReviewService::class);
            foreach ([ReviewType::MidYear, ReviewType::YearEnd] as $type) {
                if (! $reviews->canSubmitSelfAssessment($agreement, $type, $user)) {
                    continue;
                }
                $status = $agreement->reviews()->where('review_type', $type->value)->value('status');
                if ($status === ReviewStatus::Returned) {
                    $actions[] = ['key' => 'review_returned', 'review' => $type->value];
                } elseif ($reviews->selfAssessmentDue($agreement, $type)) {
                    $actions[] = ['key' => 'self_assessment', 'review' => $type->value];
                }
            }

            // Scores reach the employee only once released (as on My Performance).
            $result = $agreement->results()->where('is_current', true)->first();
            $released = $result !== null && $result->status === ResultStatus::Released ? $result : null;
            if ($released !== null) {
                $appealUntil = $released->released_at?->copy()->addDays($settings->appealWindowDays())->endOfDay();
                $appealed = PerformanceAppeal::query()->where('result_id', $released->getKey())
                    ->whereIn('status', [AppealStatus::Submitted, AppealStatus::UnderReview])->exists();
                if ($user->can('performance_appeals.create') && ! $appealed && $appealUntil !== null && now()->lte($appealUntil)) {
                    $actions[] = ['key' => 'result_released'];
                }
            }

            return [
                'agreement' => [
                    'id' => $agreement->getKey(),
                    'status' => $agreement->status->value,
                    'cycle' => ['name_en' => $agreement->cycle?->name_en, 'name_am' => $agreement->cycle?->name_am],
                    'effective_from' => $agreement->effective_from?->toDateString(),
                    'effective_to' => $agreement->effective_to?->toDateString(),
                ],
                'kpis' => $kpis->take(5)->all(),
                'counts' => [
                    'total' => $kpis->count(),
                    'on_track' => $kpis->where('health', KpiHealth::OnTrack->value)->count(),
                    'attention' => $kpis->whereIn('health', [KpiHealth::AtRisk->value, KpiHealth::OffTrack->value])->count(),
                    'not_reported' => $kpis->where('health', KpiHealth::NotReported->value)->count(),
                ],
                'result' => $released === null ? null : [
                    'final_score' => $released->final_score,
                    'rating_en' => $released->rating_label_en,
                    'rating_am' => $released->rating_label_am,
                ],
                'actions' => $actions,
            ];
        } catch (Throwable $exception) {
            Log::warning('Employee dashboard performance panel unavailable', [
                'employee_id' => $employee->id,
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    public function myEntitlements(
        Request $request,
        CafeteriaLedgerService $ledgerService,
        CafeteriaSubsidyRuleResolver $ruleResolver,
        CafeteriaAvailableSubsidyService $availableSubsidyService,
        WorkingDayCalendarService $workingDayService,
    ): Response {
        $user = $request->user();
        $employee = $user->employee;

        if ($employee === null) {
            return Inertia::render('Employee/MyEntitlements', [
                'entitlements' => [],
                'has_employee' => false,
            ]);
        }

        $today = Carbon::today();

        $entitlements = $employee->entitlements()
            ->with(['serviceType:id,name_en,name_am,code', 'serviceProvider:id,name,code'])
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
            ->orderByDesc('effective_from')
            ->get()
            ->map(function ($e) use ($employee, $today, $ledgerService, $ruleResolver, $availableSubsidyService, $workingDayService) {
                $base = [
                    'id' => $e->id,
                    'status' => $e->status,
                    'service' => $e->serviceType?->name_en,
                    'service_am' => $e->serviceType?->name_am,
                    'service_code' => $e->serviceType?->code,
                    'provider' => $e->serviceProvider?->name,
                    'quota_limit' => $e->quota_limit,
                    'quota_used' => $e->quota_used,
                    'effective_from' => $e->effective_from?->toDateString(),
                    'effective_to' => $e->effective_to?->toDateString(),
                    'activity' => null,
                ];

                if ($e->status !== EntitlementStatus::Active) {
                    return $base;
                }

                $isCafeteria = $e->serviceType?->code === 'cafeteria';
                $isTransport = $e->serviceType?->code === 'transport';

                // Set a non-null empty shell matching the full structure so the
                // component never crashes even when the real build throws.
                $base['activity'] = $isCafeteria
                    ? [
                        'type' => 'cafeteria',
                        'daily' => ['date' => $today->toDateString(), 'consumed' => false, 'subsidy' => 0.0],
                        'weekly' => [
                            'week_start' => $today->copy()->startOfWeek(Carbon::MONDAY)->toDateString(),
                            'week_end' => $today->copy()->startOfWeek(Carbon::MONDAY)->addDays(4)->toDateString(),
                            'days_consumed' => 0,
                            'days_total' => 5,
                            'days_remaining' => 0,
                            'subsidy_used' => 0.0,
                            'subsidy_remaining' => null,
                            'daily_rate' => null,
                            'balance' => 0.0,
                            'pending' => 0.0,
                        ],
                        'monthly' => ['label' => $today->format('F Y'), 'month' => (int) $today->format('n'), 'year' => (int) $today->format('Y'), 'days_consumed' => 0, 'working_days' => 0, 'subsidy_used' => 0.0],
                        'yearly' => ['year' => (int) $today->format('Y'), 'days_consumed' => 0, 'subsidy_used' => 0.0],
                        'employee' => [
                            'id' => $employee->id,
                            'name' => $employee->full_name,
                            'number' => $employee->employee_number,
                        ],
                        'today' => $today->toDateString(),
                        'consumed' => (object) [],
                        'holidays' => [],
                        'window_start' => $today->copy()->subMonths(13)->startOfMonth()->toDateString(),
                        'window_end' => $today->copy()->addMonth()->endOfMonth()->toDateString(),
                        'transactions' => [],
                    ]
                    : ($isTransport
                        ? ['type' => 'transport', 'passes' => [], 'rides_this_month' => 0, 'transactions' => []]
                        : ['type' => 'service', 'transactions' => []]);

                try {
                    $base['activity'] = $isCafeteria
                        ? $this->buildCafeteriaActivity(
                            $employee, $today,
                            $ledgerService, $ruleResolver,
                            $availableSubsidyService, $workingDayService,
                        )
                        : ($isTransport
                            ? $this->buildTransportActivity($employee->id, $today)
                            : $this->buildServiceActivity($employee->id, $e->service_type_id));
                } catch (Throwable $th) {
                    Log::warning('Employee entitlement activity failed', [
                        'entitlement_id' => $e->id,
                        'error' => $th->getMessage(),
                    ]);
                }

                return $base;
            })
            ->all();

        return Inertia::render('Employee/MyEntitlements', [
            'entitlements' => $entitlements,
            'has_employee' => true,
        ]);
    }

    private function buildCafeteriaActivity(
        Employee $employee,
        Carbon $today,
        CafeteriaLedgerService $ledger,
        CafeteriaSubsidyRuleResolver $resolver,
        CafeteriaAvailableSubsidyService $available,
        WorkingDayCalendarService $workingDays,
    ): array {
        $rule = $resolver->resolve($employee, $today);
        $balance = $ledger->getBalance($employee);
        $pending = $ledger->getPendingDeduction($employee);
        $dailyAmount = $rule ? (float) $rule->subsidy_amount : null;
        $availData = $rule ? $available->calculate($employee, $today, $rule) : null;

        // Week bounds (Mon–Fri)
        $weekMon = $today->copy()->startOfWeek(Carbon::MONDAY);
        $weekFri = $weekMon->copy()->addDays(4);

        // Wide window so the client can freely navigate months in either the
        // Gregorian or Ethiopian calendar (Ethiopian months straddle two
        // Gregorian months and the Ethiopian year is offset by ~8 years).
        $windowStart = $today->copy()->subMonths(13)->startOfMonth();
        $windowEnd = $today->copy()->addMonth()->endOfMonth();

        /** @var array<string, array{date:string,subsidy:float}> */
        $consumedByDate = [];

        CafeteriaTransactionConsumedDay::query()
            ->where('employee_id', $employee->id)
            ->whereNull('reversed_at')
            ->whereBetween('consumed_date', [$windowStart->toDateString(), $windowEnd->toDateString()])
            ->orderBy('consumed_date')
            ->get(['consumed_date', 'subsidy_amount'])
            ->each(function ($r) use (&$consumedByDate): void {
                $ds = is_string($r->consumed_date)
                    ? $r->consumed_date
                    : Carbon::parse($r->consumed_date)->toDateString();
                $consumedByDate[$ds] = [
                    'date' => $ds,
                    'subsidy' => (float) ($r->subsidy_amount ?? 0),
                ];
            });

        // Holiday dates within the window (flat list of Gregorian ISO strings)
        $holidayDates = $workingDays->holidaysBetween($windowStart, $windowEnd)
            ->map(fn ($h) => Carbon::parse($h->holiday_date ?? $h->date ?? $h)->toDateString())
            ->values()
            ->all();

        // Also load yearly totals (may extend beyond the 3-month window)
        $yearStart = $today->copy()->startOfYear();
        $yearlyConsumed = CafeteriaTransactionConsumedDay::query()
            ->where('employee_id', $employee->id)
            ->whereNull('reversed_at')
            ->whereBetween('consumed_date', [$yearStart->toDateString(), $windowEnd->toDateString()])
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(subsidy_amount), 0) as total')
            ->first();

        // ── Daily ─────────────────────────────────────────────────────────
        $todayStr = $today->toDateString();
        $todayRow = $consumedByDate[$todayStr] ?? null;
        $daily = [
            'date' => $todayStr,
            'consumed' => $todayRow !== null,
            'subsidy' => $todayRow['subsidy'] ?? 0.0,
        ];

        // ── Weekly ────────────────────────────────────────────────────────
        $weekStart = $weekMon->toDateString();
        $weekEnd = $weekFri->toDateString();
        $weekConsumed = array_filter($consumedByDate, fn ($r) => $r['date'] >= $weekStart && $r['date'] <= $weekEnd);
        $weekSubsidy = array_sum(array_column($weekConsumed, 'subsidy'));
        $weekDays = $workingDays->workingDaysBetween($weekMon, $weekFri);
        $weekly = [
            'week_start' => $weekStart,
            'week_end' => $weekEnd,
            'days_consumed' => count($weekConsumed),
            'days_total' => $weekDays,
            'days_remaining' => $availData['available_days_count'] ?? max(0, $weekDays - count($weekConsumed)),
            'subsidy_used' => round($weekSubsidy, 2),
            'subsidy_remaining' => $availData ? round((float) $availData['remaining'], 2) : null,
            'daily_rate' => $dailyAmount,
            'balance' => round($balance, 2),
            'pending' => round($pending, 2),
        ];

        // ── Monthly ───────────────────────────────────────────────────────
        $mStart = $today->copy()->startOfMonth()->toDateString();
        $mEnd = $today->copy()->endOfMonth()->toDateString();
        $monthConsumed = array_filter($consumedByDate, fn ($r) => $r['date'] >= $mStart && $r['date'] <= $mEnd);
        $monthSubsidy = array_sum(array_column($monthConsumed, 'subsidy'));
        $monthWorkDays = $workingDays->workingDaysBetween(
            Carbon::parse($mStart),
            Carbon::parse($mEnd),
        );
        $monthly = [
            'label' => $today->format('F Y'),
            'month' => (int) $today->format('n'),
            'year' => (int) $today->format('Y'),
            'days_consumed' => count($monthConsumed),
            'working_days' => $monthWorkDays,
            'subsidy_used' => round($monthSubsidy, 2),
        ];

        // ── Yearly ────────────────────────────────────────────────────────
        $yearly = [
            'year' => (int) $today->format('Y'),
            'days_consumed' => (int) ($yearlyConsumed?->cnt ?? 0),
            'subsidy_used' => round((float) ($yearlyConsumed?->total ?? 0), 2),
        ];

        // ── Flat day-metadata map (Gregorian ISO keyed) ───────────────────
        // The client builds the calendar grid in the active calendar system
        // (Ethiopian for `am`, Gregorian for `en`) and looks each day up here
        // by its Gregorian ISO date — exactly like the cafeteria scan page.
        $consumedMap = [];
        foreach ($consumedByDate as $ds => $row) {
            $consumedMap[$ds] = $row['subsidy'];
        }

        // ── Transactions ──────────────────────────────────────────────────
        $transactions = CafeteriaTransaction::query()
            ->where('employee_id', $employee->id)
            ->with('provider:id,name_en,name_am')
            ->orderByDesc('transaction_date')
            ->orderByDesc('transaction_time')
            ->limit(15)
            ->get()
            ->map(fn ($t) => [
                'date' => $t->transaction_date?->toDateString(),
                'time' => $this->formatNullableTime($t->transaction_time),
                'subsidy' => (float) ($t->subsidy_amount_applied ?? 0),
                'meal_amount' => (float) ($t->meal_amount ?? 0),
                'employee_pays' => (float) ($t->employee_payable_amount ?? 0),
                'provider' => $t->provider?->name_en,
                'status' => $t->status?->value,
            ])
            ->all();

        return [
            'type' => 'cafeteria',
            'daily' => $daily,
            'weekly' => $weekly,
            'monthly' => $monthly,
            'yearly' => $yearly,
            'employee' => [
                'id' => $employee->id,
                'name' => $employee->full_name,
                'number' => $employee->employee_number,
            ],
            'today' => $todayStr,
            'consumed' => $consumedMap,                 // { "YYYY-MM-DD": subsidyFloat }
            'holidays' => $holidayDates,                // ["YYYY-MM-DD", ...]
            'window_start' => $windowStart->toDateString(),
            'window_end' => $windowEnd->toDateString(),
            'transactions' => $transactions,
        ];
    }

    /**
     * Transport lives in its own module (passes and boarding scans), not in
     * the generic service_transactions table, so it is read from there.
     * Nothing security-relevant leaves: no QR hashes, nonces or metadata.
     *
     * @return array<string, mixed>
     */
    private function buildTransportActivity(string $employeeId, Carbon $today): array
    {
        $passes = TransportPass::query()
            ->where('employee_id', $employeeId)
            ->with(['route:id,route_code,name_en,name_am,origin_en,origin_am,destination_en,destination_am', 'provider:id,name_en,name_am'])
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
            ->orderByDesc('valid_until')
            ->limit(5)
            ->get()
            ->map(fn (TransportPass $pass) => [
                'id' => $pass->id,
                'status' => (string) $pass->status,
                'route' => $pass->route?->name_en,
                'route_am' => $pass->route?->name_am,
                'route_code' => $pass->route?->route_code,
                'origin' => $pass->route?->origin_en,
                'origin_am' => $pass->route?->origin_am,
                'destination' => $pass->route?->destination_en,
                'destination_am' => $pass->route?->destination_am,
                'provider' => $pass->provider?->name_en,
                'provider_am' => $pass->provider?->name_am,
                'valid_from' => $pass->valid_from?->toDateString(),
                'valid_until' => $pass->valid_until?->toDateString(),
            ])
            ->all();

        $monthStart = $today->copy()->startOfMonth()->toDateString();

        return [
            'type' => 'transport',
            'passes' => $passes,
            'rides_this_month' => TransportTransaction::query()
                ->where('employee_id', $employeeId)
                ->where('status', 'accepted')
                ->where('transaction_date', '>=', $monthStart)
                ->count(),
            'transactions' => TransportTransaction::query()
                ->where('employee_id', $employeeId)
                ->with(['route:id,route_code,name_en,name_am', 'provider:id,name_en,name_am'])
                ->orderByDesc('scanned_at')
                ->limit(15)
                ->get()
                ->map(fn (TransportTransaction $tx) => [
                    'date' => $tx->transaction_date?->toDateString() ?? $tx->scanned_at?->toDateString(),
                    'time' => $tx->scanned_at?->format('H:i'),
                    'route' => $tx->route?->name_en,
                    'route_am' => $tx->route?->name_am,
                    'provider' => $tx->provider?->name_en,
                    'provider_am' => $tx->provider?->name_am,
                    'status' => (string) $tx->status,
                    'rejection_reason' => $tx->status === 'accepted' ? null : $tx->rejection_reason,
                ])
                ->all(),
        ];
    }

    private function buildServiceActivity(string $employeeId, ?string $serviceTypeId): array
    {
        $txns = ServiceTransaction::query()
            ->where('employee_id', $employeeId)
            ->when($serviceTypeId, fn ($q) => $q->where('service_type_id', $serviceTypeId))
            ->with('serviceType:id,name_en,name_am', 'serviceProvider:id,name')
            ->orderByDesc('occurred_at')
            ->limit(15)
            ->get()
            ->map(fn ($t) => [
                'date' => $this->formatNullableDate($t->occurred_at),
                'time' => $this->formatNullableTime($t->occurred_at),
                'service' => $t->serviceType?->name_en,
                'service_am' => $t->serviceType?->name_am,
                'provider' => $t->serviceProvider?->name,
                'amount' => $t->amount !== null ? (float) $t->amount : null,
                'status' => $t->status?->value ?? $t->status,
            ])
            ->all();

        return [
            'type' => 'service',
            'transactions' => $txns,
        ];
    }

    private function formatNullableDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof Carbon) {
            return $value->toDateString();
        }

        return Carbon::parse($value)->toDateString();
    }

    private function formatNullableTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof Carbon) {
            return $value->format('H:i');
        }

        if (is_string($value) && preg_match('/^\d{2}:\d{2}/', $value) === 1) {
            return substr($value, 0, 5);
        }

        return Carbon::parse($value)->format('H:i');
    }

    /**
     * Open transfer announcements, inside the portal.
     *
     * The public listing renders in PublicLayout, so following an announcement
     * from the portal used to drop the employee out of the signed-in chrome
     * and onto the public site. These three screens keep the whole
     * browse → read → apply path under /my-portal; the presenter and the
     * submit action are shared with the public pages so the two never drift.
     */
    public function announcements(Request $request): Response
    {
        $announcements = TransferAnnouncement::query()
            ->where('status', TransferAnnouncementStatus::Published)
            ->with(['organization', 'position'])
            ->orderByDesc('closing_date')
            ->orderByDesc('published_at')
            ->paginate(15)
            ->through(fn (TransferAnnouncement $a): array => PublicTransferAnnouncementController::presentSummary($a));

        $employee = $request->user()?->employee;

        return Inertia::render('Employee/Announcements', [
            'announcements' => $announcements,
            'applied_ids' => $employee === null ? [] : $this->activeApplicationAnnouncementIds($employee),
            'has_employee' => $employee !== null,
        ]);
    }

    public function announcementShow(Request $request, TransferAnnouncement $announcement): Response
    {
        abort_unless($announcement->status === TransferAnnouncementStatus::Published, 404);

        $announcement->loadMissing(['organization', 'position']);
        $employee = $request->user()?->employee;

        return Inertia::render('Employee/AnnouncementShow', [
            'announcement' => $this->presentAnnouncementDetail($announcement),
            'already_applied' => $employee !== null
                && in_array($announcement->id, $this->activeApplicationAnnouncementIds($employee), true),
            'has_employee' => $employee !== null,
        ]);
    }

    public function announcementApply(Request $request, TransferAnnouncement $announcement): Response|RedirectResponse
    {
        if (! $announcement->isAcceptingApplications()) {
            return $this->backToAnnouncement($announcement, __('transfers.notAcceptingApplications'));
        }

        $employee = $request->user()?->employee;

        if ($employee === null) {
            return $this->backToAnnouncement($announcement, __('transfers.noEmployeeProfile'));
        }

        if (in_array($announcement->id, $this->activeApplicationAnnouncementIds($employee), true)) {
            return $this->backToAnnouncement($announcement, __('transfers.alreadyApplied'));
        }

        $announcement->loadMissing(['organization', 'position']);

        return Inertia::render('Employee/AnnouncementApply', [
            'announcement' => $this->presentAnnouncementDetail($announcement),
        ]);
    }

    public function announcementApplyStore(
        PublicStoreTransferApplicationRequest $request,
        TransferAnnouncement $announcement,
        SubmitTransferApplicationAction $action,
    ): RedirectResponse {
        $employee = $request->user()?->employee;

        if ($employee === null) {
            return $this->backToAnnouncement($announcement, __('transfers.noEmployeeProfile'));
        }

        try {
            $action->execute($announcement, $employee, $request->user(), [
                'cover_letter' => $request->input('cover_letter'),
                'documents' => $request->file('documents') ?? [],
            ]);
        } catch (DomainException $e) {
            return back()->withErrors(['application' => $e->getMessage()]);
        }

        return to_route('employee.announcements.show', $announcement)->with('flash', [
            'message' => __('transfers.applicationSubmitted'),
            'type' => 'success',
        ]);
    }

    /**
     * Announcement ids this employee holds a live application against.
     *
     * @return array<int, string>
     */
    private function activeApplicationAnnouncementIds(Employee $employee): array
    {
        return TransferApplication::query()
            ->where('employee_id', $employee->id)
            ->whereNotIn('status', [
                TransferApplicationStatus::Withdrawn->value,
                TransferApplicationStatus::Cancelled->value,
            ])
            ->pluck('announcement_id')
            ->all();
    }

    /** @return array<string, mixed> */
    private function presentAnnouncementDetail(TransferAnnouncement $announcement): array
    {
        return [
            ...PublicTransferAnnouncementController::presentSummary($announcement),
            'salary_min' => $announcement->salary_min,
            'salary_max' => $announcement->salary_max,
            'eligibility_rules' => $announcement->eligibility_rules,
            'required_documents' => $announcement->required_documents,
            'status' => $announcement->status->value,
        ];
    }

    private function backToAnnouncement(TransferAnnouncement $announcement, string $message): RedirectResponse
    {
        return to_route('employee.announcements.show', $announcement)
            ->with('flash', ['message' => $message, 'type' => 'error']);
    }

    public function myTransferApplications(Request $request): Response
    {
        $user = $request->user();
        $employee = $user->employee;

        $applications = [];

        if ($employee !== null) {
            $applications = TransferApplication::query()
                ->where('employee_id', $employee->id)
                ->with(['announcement.organization', 'announcement.position'])
                ->orderByDesc('submitted_at')
                ->get()
                ->map(fn (TransferApplication $app) => [
                    'id' => $app->id,
                    'status' => $app->status?->value,
                    'status_label' => $app->status?->label(),
                    'submitted_at' => $app->submitted_at?->toDateString(),
                    'applicant_notes' => $app->applicant_notes,
                    'organization_name' => $app->announcement?->organization?->name_en,
                    'position_title' => $app->announcement?->position?->title_en,
                    'announcement_id' => $app->announcement_id,
                    'closing_date' => $app->announcement?->closing_date?->toDateString(),
                    'rejected_reason' => $app->rejected_reason,
                ])
                ->all();
        }

        return Inertia::render('Employee/MyTransferApplications', [
            'applications' => $applications,
            'has_employee' => $employee !== null,
        ]);
    }
}
