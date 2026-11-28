<?php

declare(strict_types=1);

namespace Modules\Leave\Http\Controllers;

use App\Enums\EmployeeStatus;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\Employees\EmployeeWorkContextResolver;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Leave\Application\Services\LeaveAccess;
use Modules\Leave\Application\Services\LeaveBalanceService;
use Modules\Leave\Application\Services\LeaveCalendar;
use Modules\Leave\Application\Services\LeaveGrantService;
use Modules\Leave\Application\Services\LeaveLedger;
use Modules\Leave\Application\Services\LeavePresenter;
use Modules\Leave\Application\Services\LeaveRules;
use Modules\Leave\Domain\Enums\LeaveEntryType;
use Modules\Leave\Domain\Models\LeaveLedgerEntry;
use Modules\Leave\Domain\Models\LeaveRule;
use Modules\Leave\Domain\Models\LeaveType;
use Modules\Leave\Http\Support\LeavePopups;

/**
 * Leave balances of the employees in scope, one employee's ledger, and the
 * three ways a person changes a balance: an opening balance (one by one or
 * from a file), a correction, and a postponement.
 */
class BalanceController extends Controller
{
    public function __construct(
        private readonly LeaveAccess $access,
        private readonly LeaveBalanceService $balances,
        private readonly LeavePresenter $presenter,
        private readonly LeaveCalendar $calendar,
        private readonly OrganizationScopeService $scope,
        private readonly EmployeeWorkContextResolver $context,
    ) {}

    public function index(Request $request, LeaveGrantService $grants, LeaveRules $rules, LeavePopups $popups): Response
    {
        $user = $request->user();
        abort_unless($user->can('leave.view_org'), 403);
        $search = is_string($request->query('search')) ? trim((string) $request->query('search')) : '';

        $query = Employee::query()->where('status', EmployeeStatus::Active->value)
            ->when($search !== '', function (Builder $q) use ($search): void {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $q->where(fn (Builder $inner) => $inner->where('employee_number', 'like', $like)->orWhere('full_name', 'like', $like)->orWhere('name_en', 'like', $like));
            });
        $inScope = $this->scope->employeeIdsInScope($user);
        if ($inScope !== null) {
            $query->whereIn('id', $inScope);
        }
        $page = $query->orderBy('full_name')->paginate(20)->withQueryString();

        // The budget year (Hamle 1 to Sene 30) of today and the one before it: each has its own column.
        $today = $this->calendar->today();
        $currentYear = $this->calendar->budgetYearOf($today);
        $years = [$currentYear - 1, $currentYear];

        // Bring the employees on this page up to date first, so the current year never reads zero for want of its grant.
        foreach ($page->getCollection() as $employee) {
            try {
                $grants->settle($employee, $today);
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        $types = LeaveType::query()->active()->where('uses_balance', true)->orderBy('sort_order')->get();
        // What is left of each budget year's days, from the ledger, for the page of employees only.
        $totals = LeaveLedgerEntry::query()
            ->whereIn('employee_id', $page->getCollection()->pluck('id'))->whereIn('leave_type_id', $types->pluck('id'))
            ->whereIn('entitlement_year', $years)
            ->groupBy('employee_id', 'leave_type_id', 'entitlement_year')
            ->get(['employee_id', 'leave_type_id', 'entitlement_year', DB::raw('SUM(days) as days')]);

        return Inertia::render('modules/leave/Balances', [
            'leaveEmployees' => $page->through(fn (Employee $employee): array => [
                ...(array) $this->presenter->employee($employee),
                'service_start_date' => $employee->service_start_date?->toDateString(),
                // Keyed "<leave type id>:<budget year>".
                'totals' => $totals->where('employee_id', $employee->id)->mapWithKeys(fn ($row): array => ["{$row->leave_type_id}:{$row->entitlement_year}" => round((float) $row->days, 1)])->all(),
            ]),
            'leaveTypes' => $types->map(fn (LeaveType $type): array => [
                'id' => $type->id, 'name_en' => $type->name_en, 'name_am' => $type->name_am,
                // A type whose unused days end with the year has nothing left of last year to show.
                'years' => ($rules->ruleFor($type, $today)?->carry_over_mode ?? LeaveRule::CARRY_NONE) === LeaveRule::CARRY_NONE ? [$currentYear] : $years,
            ])->all(),
            'budgetYears' => ['previous' => $currentYear - 1, 'current' => $currentYear, 'starts_on' => $this->calendar->budgetYearStart($currentYear), 'ends_on' => $this->calendar->budgetYearEnd($currentYear)],
            'filters' => ['search' => $search],
            'can' => [
                'import' => $user->can('leave.adjust_balance'),
                'adjust' => $user->can('leave.adjust_balance'),
                'postpone' => $user->can('leave.postpone'),
            ],
            // The employee whose popup is open (opening balance, ledger, postponement).
            'leaveSelected' => $popups->employee($request),
            'today' => $today,
        ]);
    }

    public function show(Request $request, Employee $employee): Response
    {
        $user = $request->user();
        abort_unless($this->access->canManageEmployee($user, $employee, 'leave.view_org'), 403);

        return Inertia::render('modules/leave/EmployeeBalance', [
            'leaveEmployee' => [...(array) $this->presenter->employee($employee), 'service_start_date' => $employee->service_start_date?->toDateString()],
            'leaveSummary' => $this->balances->summary($employee),
            'ledger' => $this->balances->ledgerOf($employee),
            'can' => [
                'adjust' => $this->access->canManageEmployee($user, $employee, 'leave.adjust_balance'),
                'postpone' => $this->access->canManageEmployee($user, $employee, 'leave.postpone'),
            ],
        ]);
    }

    public function adjust(Request $request, Employee $employee): RedirectResponse
    {
        $user = $request->user();
        abort_unless($this->access->canManageEmployee($user, $employee, 'leave.adjust_balance'), 403);
        $data = $request->validate([
            'leave_type_id' => ['required', 'uuid'],
            'entitlement_year' => ['required', 'integer', 'min:1990', 'max:2200'],
            'days' => ['required', 'numeric', 'min:-366', 'max:366'],
            'kind' => ['required', 'in:opening,adjustment'],
            'reason' => ['required', 'string', 'max:1000'],
        ], [], ['days' => __('leave::messages.fields.days'), 'reason' => __('leave::messages.fields.reason'), 'entitlement_year' => __('leave::messages.fields.entitlement_year')]);

        $this->balances->adjust($user, $employee, LeaveType::query()->findOrFail($data['leave_type_id']), (int) $data['entitlement_year'],
            round((float) $data['days'] * 2) / 2, $data['reason'], $data['kind'] === 'opening', $this->organizationOf($employee));

        return back()->with('success', __('leave::messages.flash.balance_adjusted'));
    }

    public function postpone(Request $request, Employee $employee): RedirectResponse
    {
        $user = $request->user();
        abort_unless($this->access->canManageEmployee($user, $employee, 'leave.postpone'), 403);
        $data = $request->validate([
            'leave_type_id' => ['required', 'uuid'],
            'entitlement_year' => ['required', 'integer', 'min:1990', 'max:2200'],
            'until_year' => ['required', 'integer', 'min:1990', 'max:2200'],
            'reason' => ['required', 'string', 'max:1000'],
        ], [], ['until_year' => __('leave::messages.fields.until_year'), 'reason' => __('leave::messages.fields.reason')]);

        $this->balances->postpone($user, $employee, LeaveType::query()->findOrFail($data['leave_type_id']), (int) $data['entitlement_year'],
            (int) $data['until_year'], $data['reason'], $this->organizationOf($employee));

        return back()->with('success', __('leave::messages.flash.postponed'));
    }

    /**
     * Opening balances from a CSV file: employee_number, leave_type_code,
     * entitlement_year, days. A line is taken once: an opening balance that
     * already exists for the employee, type and year is left alone, so the
     * same file may be uploaded again after its errors are fixed.
     */
    public function import(Request $request, LeaveLedger $ledger): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('leave.adjust_balance'), 403);
        $request->validate(['file' => ['required', 'file', 'max:2048', 'mimes:csv,txt']], [], ['file' => __('leave::messages.fields.file')]);

        $handle = fopen($request->file('file')->getRealPath(), 'rb');
        abort_if($handle === false, 422);
        $types = LeaveType::query()->where('uses_balance', true)->get()->keyBy('code');
        $written = 0;
        $skipped = 0;
        $errors = [];
        $line = 0;
        while (($row = fgetcsv($handle, 1000, ',', '"', '')) !== false) {
            $line++;
            $row = array_map(static fn ($cell): string => trim((string) $cell), $row);
            if ($line === 1 && ! is_numeric($row[2] ?? null)) {
                continue; // the header line
            }
            if (count(array_filter($row, static fn (string $cell): bool => $cell !== '')) === 0) {
                continue;
            }
            if ($line > 5001) {
                $errors[] = __('leave::messages.import.too_many_lines');
                break;
            }
            [$number, $code, $year, $days] = array_pad($row, 4, '');
            $number = preg_replace('/^\xEF\xBB\xBF/', '', $number) ?? $number;
            $employee = Employee::query()->where('employee_number', $number)->first();
            $type = $types->get($code);
            $problem = match (true) {
                $employee === null => 'unknown_employee',
                ! $this->access->canManageEmployee($user, $employee, 'leave.adjust_balance') => 'out_of_scope',
                $type === null => 'unknown_type',
                ! ctype_digit($year) || (int) $year < 1990 || (int) $year > 2200 => 'bad_year',
                ! is_numeric($days) || (float) $days <= 0 || (float) $days > 366 => 'bad_days',
                default => null,
            };
            if ($problem !== null) {
                if (count($errors) < 20) {
                    $errors[] = __('leave::messages.import.line', ['line' => $line]).' '.__("leave::messages.import.{$problem}");
                }

                continue;
            }
            $entry = $ledger->write($employee->id, $type->id, (int) $year, LeaveEntryType::Opening, round((float) $days * 2) / 2, [
                'note' => 'Opening balance (file).', 'created_by' => $user->getKey(), 'organization_id' => $this->organizationOf($employee),
                'unique_key' => "opening:{$employee->id}:{$type->id}:{$year}",
            ]);
            $entry === null ? $skipped++ : $written++;
        }
        fclose($handle);

        $response = back()->with('success', __('leave::messages.import.done', ['written' => $written, 'skipped' => $skipped]));

        return $errors === [] ? $response : $response->with('warning', implode(' ', $errors));
    }

    private function organizationOf(Employee $employee): ?string
    {
        return $this->context->assignmentOn($employee, Carbon::parse($this->calendar->today()))?->organization_id;
    }
}
