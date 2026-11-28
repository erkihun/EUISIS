<?php

declare(strict_types=1);

namespace Modules\Leave\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Leave\Application\Services\LeaveAccess;
use Modules\Leave\Application\Services\LeaveCalendar;
use Modules\Leave\Application\Services\LeavePresenter;
use Modules\Leave\Contracts\Data\LeaveStatus;
use Modules\Leave\Domain\Models\LeaveRequest;
use Modules\Leave\Domain\Models\LeaveType;
use Modules\Leave\Http\Support\LeavePopups;

/**
 * Leave in the institutions in scope: who is away on a day, who will be away
 * soon, and what was taken per leave type in the budget year.
 */
class ReportController extends Controller
{
    private const RELATIONS = ['employee', 'leaveType', 'organization:id,name_en,name_am', 'organizationUnit:id,name_en,name_am'];

    public function __invoke(Request $request, LeaveAccess $access, LeaveCalendar $calendar, LeavePresenter $presenter, LeavePopups $popups): Response
    {
        $user = $request->user();
        abort_unless($user->can('leave.reports.view'), 403);

        $today = $calendar->today();
        $date = is_string($request->query('date')) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query('date')) === 1 ? (string) $request->query('date') : $today;
        $budgetYear = $calendar->budgetYearOf($date);
        $scoped = fn () => $access->constrainToScope(LeaveRequest::query(), $user);
        $rows = fn ($query): array => $query->with(self::RELATIONS)->limit(200)->get()->map(fn (LeaveRequest $row): array => $presenter->row($row))->all();

        $taken = $scoped()->where('budget_year', $budgetYear)->where('status', LeaveStatus::Approved->value)
            ->groupBy('leave_type_id')->selectRaw('leave_type_id, COUNT(*) as requests, SUM(days_counted) as days')->get()->keyBy('leave_type_id');

        return Inertia::render('modules/leave/Reports', [
            'reportDate' => $date,
            'budgetYear' => $budgetYear,
            'onLeave' => $rows($scoped()->where('status', LeaveStatus::Approved->value)->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date)->orderBy('ends_on')),
            'upcoming' => $rows($scoped()->where('status', LeaveStatus::Approved->value)->whereDate('starts_on', '>', $date)->whereDate('starts_on', '<=', $calendar->addDays($date, 30))->orderBy('starts_on')),
            'byType' => LeaveType::query()->orderBy('sort_order')->get()->map(fn (LeaveType $type): array => [
                'id' => $type->id, 'name_en' => $type->name_en, 'name_am' => $type->name_am, 'day_unit' => $type->day_unit,
                'requests' => (int) ($taken->get($type->id)->requests ?? 0),
                'days' => round((float) ($taken->get($type->id)->days ?? 0), 1),
            ])->all(),
            'leaveOpenRequest' => $popups->request($request),
            'today' => $today,
            'waiting' => [
                'supervisor' => $scoped()->where('status', LeaveStatus::PendingSupervisor->value)->count(),
                'hr' => $scoped()->where('status', LeaveStatus::PendingHr->value)->count(),
            ],
        ]);
    }
}
