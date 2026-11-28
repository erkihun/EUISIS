<?php

declare(strict_types=1);

namespace Modules\Leave\Application\Queries;

use App\Models\Employee;
use Modules\Leave\Application\Services\LeaveBalanceService;
use Modules\Leave\Contracts\Data\LeaveStatus;
use Modules\Leave\Contracts\EmployeeLeaveSummary;
use Modules\Leave\Domain\Models\LeaveRequest;

final readonly class EmployeeLeaveSummaryQuery implements EmployeeLeaveSummary
{
    public function __construct(private LeaveBalanceService $balances) {}

    public function forEmployee(string $employeeId, string $today): ?array
    {
        $employee = Employee::query()->find($employeeId);
        if ($employee === null) {
            return null;
        }
        $summary = $this->balances->summary($employee);
        $next = LeaveRequest::query()->with('leaveType:id,name_en,name_am')
            ->where('employee_id', $employeeId)->where('status', LeaveStatus::Approved->value)
            ->whereDate('ends_on', '>=', $today)->orderBy('starts_on')->first();

        return [
            'budget_year' => $summary['budget_year'],
            'balances' => array_values(array_map(
                static fn (array $type): array => ['code' => $type['code'], 'name_en' => $type['name_en'], 'name_am' => $type['name_am'], 'available' => (float) $type['free'], 'pending' => (float) $type['pending']],
                array_filter($summary['types'], static fn (array $type): bool => (bool) $type['uses_balance']),
            )),
            'pending_requests' => LeaveRequest::query()->where('employee_id', $employeeId)->whereIn('status', LeaveStatus::values(LeaveStatus::PENDING))->count(),
            'next_leave' => $next === null ? null : [
                'starts_on' => $next->starts_on->toDateString(), 'ends_on' => $next->ends_on->toDateString(),
                'name_en' => (string) $next->leaveType?->name_en, 'name_am' => (string) $next->leaveType?->name_am,
            ],
        ];
    }
}
