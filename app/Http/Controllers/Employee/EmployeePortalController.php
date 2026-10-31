<?php

declare(strict_types=1);

namespace App\Http\Controllers\Employee;

use App\Enums\CardStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\EmployeeSelfServiceResource;
use App\Models\Employee;
use App\Models\EmployeeCorrectionRequest;
use App\Models\IdCard;
use App\Models\User;
use App\Services\Calendar\WorkCalendarService;
use App\Support\NotificationPresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Cafeteria\Contracts\EmployeeCafeteriaActivity;
use Modules\DailyWork\Contracts\Data\DailyActivityRoles;
use Modules\DailyWork\Contracts\EmployeeDailyWork;
use Modules\Entitlements\Contracts\Data\EntitlementStatus;
use Modules\Entitlements\Contracts\ReadModels\ServiceTransactionRecord;
use Modules\Performance\Contracts\EmployeePerformance;
use Modules\Transfer\Contracts\EmployeeTransferActivity;
use Modules\Transport\Contracts\EmployeeTransportActivity;
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
                // Officer roles without dashboard access land here too. For them a
                // missing employee record is expected, so the page does not send
                // them to HR; any role besides Employee marks a staff account.
                'staff_account' => $user->getRoleNames()->contains(fn (string $role): bool => $role !== DailyActivityRoles::EMPLOYEE_ROLE),
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

        // ── Transfer applications and open announcements ─────────────────────
        $transfers = app(EmployeeTransferActivity::class)->forEmployee($employee->id);

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
            'transfer_apps' => $transfers['applications'],
            'open_announcements' => $transfers['open_announcements'],
            'daily_activity' => $this->dailyActivitySummary($user, $employee),
            'performance' => app(EmployeePerformance::class)->portalSummary($user, $employee->id),
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
            $today = Carbon::parse(app(EmployeeDailyWork::class)->today());
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
            return app(EmployeeDailyWork::class)->dashboard((string) $employee->id);
        } catch (Throwable $exception) {
            Log::warning('Employee dashboard daily activity panel unavailable', [
                'employee_id' => $employee->id,
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    public function myEntitlements(Request $request): Response
    {
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
            ->map(function ($e) use ($employee, $today) {
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
                        ? app(EmployeeCafeteriaActivity::class)->forEmployee($employee->id, $today)
                        : ($isTransport
                            ? app(EmployeeTransportActivity::class)->forEmployee($employee->id, $today)->toArray()
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

    private function buildServiceActivity(string $employeeId, ?string $serviceTypeId): array
    {
        $txns = ServiceTransactionRecord::query()
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
}
