<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Enums\CafeteriaLedgerEntryType;
use App\Http\Controllers\Controller;
use App\Http\Resources\CafeteriaSubsidyLedgerResource;
use App\Models\CafeteriaSubsidyLedger;
use App\Models\Employee;
use App\Services\Cafeteria\CafeteriaLedgerService;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CafeteriaSubsidyLedgerController extends Controller
{
    public function __construct(private readonly CafeteriaLedgerService $ledgerService) {}

    public function index(Request $request, OrganizationScopeService $scope): Response
    {
        $this->authorize('viewAny', CafeteriaSubsidyLedger::class);

        $filters = $request->validate([
            'employee_id' => ['nullable', 'uuid'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('date_from') ? ['after_or_equal:date_from'] : [])],
            'entry_type' => ['nullable', Rule::enum(CafeteriaLedgerEntryType::class)],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $employeeId = $filters['employee_id'] ?? null;
        $employees = Employee::query()->whereHas('currentAssignment', fn ($query) => $scope->applyOrganizationScope($query, $request->user()))
            ->orderBy('full_name')->get(['id', 'full_name', 'employee_number']);
        $employee = $employeeId ? $employees->firstWhere('id', $employeeId) : null;
        abort_if($employeeId && ! $employee, 404);

        $query = CafeteriaSubsidyLedger::query()
            ->whereHas('employee.currentAssignment', fn ($query) => $scope->applyOrganizationScope($query, $request->user()))
            ->with('employee:id,full_name,employee_number')
            ->when($employeeId, fn ($q) => $q->where('employee_id', $employeeId))
            ->when($filters['entry_type'] ?? null, fn ($q, $value) => $q->where('entry_type', $value))
            ->when($request->string('date_from')->toString(), fn ($q, $v) => $q->whereDate('ledger_date', '>=', $v))
            ->when($request->string('date_to')->toString(), fn ($q, $v) => $q->whereDate('ledger_date', '<=', $v))
            ->orderByDesc('ledger_date')
            ->orderByDesc('created_at')->orderByDesc('id');

        $summary = (clone $query)->reorder()->selectRaw('COUNT(*) as entry_count, COALESCE(SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END), 0) as credits, COALESCE(SUM(CASE WHEN amount < 0 THEN -amount ELSE 0 END), 0) as debits')->first();

        $entries = $query->paginate(50)->withQueryString();

        $balance = $employee ? $this->ledgerService->getBalance($employee) : null;

        return Inertia::render('Cafeteria/Ledger/Index', [
            'entries' => CafeteriaSubsidyLedgerResource::collection($entries)->resolve(),
            'meta' => [
                'current_page' => $entries->currentPage(),
                'last_page' => $entries->lastPage(),
                'total' => $entries->total(),
                'per_page' => $entries->perPage(),
            ],
            'filters' => collect($filters)->except('page')->all(),
            'employees' => $employees,
            'entryTypes' => array_column(CafeteriaLedgerEntryType::cases(), 'value'),
            'summary' => ['credits' => (float) $summary->credits, 'debits' => (float) $summary->debits, 'net' => (float) $summary->credits - (float) $summary->debits],
            'employee' => $employee?->only(['id', 'full_name', 'employee_number']),
            'balance' => $balance,
        ]);
    }
}
