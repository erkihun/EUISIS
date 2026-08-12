<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Cafeteria\ProcessCafeteriaQrScanAction;
use App\Actions\Cafeteria\ReverseCafeteriaTransactionAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\ProcessCafeteriaQrScanRequest;
use App\Http\Requests\ReverseCafeteriaTransactionRequest;
use App\Http\Resources\CafeteriaTransactionResource;
use App\Models\CafeteriaProvider;
use App\Models\CafeteriaTransaction;
use App\Models\Employee;
use App\Services\Cafeteria\CafeteriaCalendarService;
use App\Services\Cafeteria\CafeteriaProviderAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class CafeteriaTransactionController extends Controller
{
    public function __construct(
        private readonly CafeteriaProviderAccessService $providerAccess,
        private readonly CafeteriaCalendarService $calendarService,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', CafeteriaTransaction::class);

        $query = CafeteriaTransaction::query()
            ->with(['employee.currentAssignment.organization', 'employee.currentAssignment.organizationUnit', 'employee.currentAssignment.position', 'provider', 'consumedDays'])
            ->when($request->string('provider_id')->toString(), fn ($q, $v) => $q->where('cafeteria_provider_id', $v))
            ->when($request->string('date')->toString(), fn ($q, $v) => $q->whereDate('transaction_date', $v))
            ->when($request->string('status')->toString(), fn ($q, $v) => $q->where('status', $v))
            ->when($request->boolean('extra_only'), fn ($q) => $q->where('is_extra_scan', true))
            ->orderByDesc('scanned_at');

        $this->providerAccess->filterProviderScopedQuery($request->user(), $query);

        $transactions = $query->paginate(30)->withQueryString();

        $providers = CafeteriaProvider::query()
            ->with('organization:id,name_en,name_am,code')
            ->where('is_active', true)
            ->when($this->providerAccess->accessibleProviderIds($request->user()) !== [], function ($query) use ($request): void {
                $query->whereIn('id', $this->providerAccess->accessibleProviderIds($request->user()));
            })
            ->orderBy('name_en')
            ->get([
                'id',
                'organization_id',
                'name_en',
                'name_am',
                'code',
                'contact_person',
                'phone_number',
                'email',
                'location',
                'is_active',
            ]);

        return Inertia::render('Cafeteria/Transactions/Index', [
            'transactions' => CafeteriaTransactionResource::collection($transactions)->resolve(),
            'meta' => [
                'current_page' => $transactions->currentPage(),
                'last_page' => $transactions->lastPage(),
                'total' => $transactions->total(),
                'per_page' => $transactions->perPage(),
            ],
            'filters' => $request->only(['provider_id', 'date', 'status', 'extra_only']),
            'providers' => $providers,
            'can' => [
                'scan' => $request->user()?->can('scan', CafeteriaTransaction::class) ?? false,
            ],
        ]);
    }

    public function show(CafeteriaTransaction $cafeteriaTransaction): Response
    {
        $this->authorize('view', $cafeteriaTransaction);

        $cafeteriaTransaction->load(['employee', 'provider', 'idCard', 'ledgerEntries']);

        return Inertia::render('Cafeteria/Transactions/Show', [
            'transaction' => (new CafeteriaTransactionResource($cafeteriaTransaction))->resolve(),
        ]);
    }

    public function scan(Request $request): Response
    {
        $this->authorize('scan', CafeteriaTransaction::class);

        $providers = CafeteriaProvider::query()
            ->with('organization:id,name_en,name_am,code')
            ->where('is_active', true)
            ->when($this->providerAccess->accessibleProviderIds($request->user()) !== [], function ($query) use ($request): void {
                $query->whereIn('id', $this->providerAccess->accessibleProviderIds($request->user()));
            })
            ->orderBy('name_en')
            ->get(['id', 'organization_id', 'name_en', 'name_am', 'code', 'contact_person', 'phone_number', 'email', 'location', 'is_active']);

        $selectedProvider = $providers->first();

        return Inertia::render('Cafeteria/Scan', [
            'providers' => $providers,
            'provider_locked' => $providers->count() === 1 && ! $this->providerAccess->canAccessAllProviders($request->user()),
            'today_scans' => $selectedProvider
                ? CafeteriaTransactionResource::collection($this->todayScansForProvider($request, $selectedProvider->id))->resolve()
                : [],
            'calendar_days' => $selectedProvider
                ? $this->calendarService->getEmployeeWeekCalendar(null, Carbon::today(), $selectedProvider)
                : [],
            'scan_result' => $request->session()->get('scan_result'),
        ]);
    }

    public function scanMobile(Request $request): Response
    {
        $this->authorize('scan', CafeteriaTransaction::class);

        $providers = CafeteriaProvider::query()
            ->with('organization:id,name_en,name_am,code')
            ->where('is_active', true)
            ->when($this->providerAccess->accessibleProviderIds($request->user()) !== [], function ($query) use ($request): void {
                $query->whereIn('id', $this->providerAccess->accessibleProviderIds($request->user()));
            })
            ->orderBy('name_en')
            ->get(['id', 'name_en', 'name_am', 'code', 'is_active']);

        $selectedProvider = $providers->first();
        $todayCount = $selectedProvider
            ? CafeteriaTransaction::query()
                ->where('cafeteria_provider_id', $selectedProvider->id)
                ->whereDate('transaction_date', Carbon::today())
                ->count()
            : 0;

        return Inertia::render('Cafeteria/MobileScan', [
            'providers' => $providers,
            'provider_locked' => $providers->count() === 1 && ! $this->providerAccess->canAccessAllProviders($request->user()),
            'today_scan_count' => $todayCount,
            'scan_result' => $request->session()->get('scan_result'),
        ]);
    }

    public function today(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CafeteriaTransaction::class);

        $provider = CafeteriaProvider::query()->findOrFail($request->string('provider_id')->toString());

        abort_unless($this->providerAccess->canAccessProvider($request->user(), $provider), 403, __('cafeteria.providerAccessDenied'));

        return response()->json([
            'data' => CafeteriaTransactionResource::collection($this->todayScansForProvider($request, $provider->id))->resolve(),
        ]);
    }

    public function calendar(Request $request): JsonResponse
    {
        $this->authorize('scan', CafeteriaTransaction::class);

        $provider = CafeteriaProvider::query()->findOrFail($request->string('provider_id')->toString());

        abort_unless($this->providerAccess->canAccessProvider($request->user(), $provider), 403, __('cafeteria.providerAccessDenied'));

        $employee = $request->filled('employee_id')
            ? Employee::query()->findOrFail($request->string('employee_id')->toString())
            : null;
        $date = $request->filled('date') ? Carbon::parse($request->string('date')->toString()) : Carbon::today();

        return response()->json([
            'calendar_days' => $this->calendarService->getEmployeeWeekCalendar($employee, $date, $provider),
        ]);
    }

    public function processScan(ProcessCafeteriaQrScanRequest $request, ProcessCafeteriaQrScanAction $action): RedirectResponse
    {
        $provider = CafeteriaProvider::findOrFail($request->validated('provider_id'));

        abort_unless($this->providerAccess->canAccessProvider($request->user(), $provider), 403, __('cafeteria.providerAccessDenied'));

        $scannedAt = $request->filled('scanned_at') ? Carbon::parse($request->validated('scanned_at')) : Carbon::now();

        $result = $action->execute(
            $request->validated('qr_token'),
            $provider,
            $scannedAt,
            $request->user(),
            $request,
            [
                'usage_mode' => $request->validated('usage_mode'),
                'scan_nonce' => $request->validated('scan_nonce'),
                'meal_amount' => $request->validated('meal_amount'),
            ],
        );

        $isMobile = $request->validated('source') === 'mobile';
        $scanRoute = $isMobile ? 'cafeteria.scan.mobile' : 'cafeteria.scan';

        if (! $result['allowed']) {
            $employee = $result['employee'] ?? null;
            $card = $result['id_card'] ?? null;
            $employeeData = $employee ? [
                'full_name' => $employee->full_name,
                'employee_number' => $employee->employee_number,
                'photo_url' => $employee->photo_path ? asset('storage/'.$employee->photo_path) : null,
                'position' => $employee->currentAssignment?->position?->title_en,
                'organization' => $employee->currentAssignment?->organization?->name_en,
                'organization_unit' => $employee->currentAssignment?->organizationUnit?->name_en,
            ] : null;

            return redirect()->route($scanRoute)->with([
                'scan_result' => [
                    'allowed' => false,
                    'is_extra_scan' => false,
                    'denial_reason' => $result['denial_reason'],
                    'denial_message' => $result['denial_message'] ?? null,
                    'employee' => $employeeData,
                    'card_number' => $card?->card_number,
                    'card_status' => $result['card_status'] ?? null,
                    'usage_mode' => $result['usage_mode'],
                    'subsidy_applied' => 0.0,
                    'employee_payable' => 0.0,
                    'available_days_count' => 0,
                    'consumed_days_count' => 0,
                    'remaining_after' => 0.0,
                    'week_start' => $result['week_start'],
                    'week_end' => $result['week_end'],
                    'consumed_dates' => [],
                    'calendar_days' => [],
                ],
                'flash' => [
                    'message' => $result['denial_message'] ?? __('cafeteria.scanDenied', ['reason' => $result['denial_reason']]),
                    'type' => 'error',
                ],
            ]);
        }

        $isExtraScan = $result['is_extra_scan'] ?? false;
        $messageKey = $isExtraScan ? 'cafeteria.extraScanRecorded' : 'cafeteria.scanRecorded';

        $transaction = $result['transaction'];
        $employeeData = null;
        $cardNumber = null;

        if ($transaction !== null) {
            $transaction->loadMissing(
                'employee.currentAssignment.organization',
                'employee.currentAssignment.organizationUnit',
                'employee.currentAssignment.position',
                'idCard',
                'consumedDays',
            );

            $employee = $transaction->employee;
            if ($employee !== null) {
                $employeeData = [
                    'full_name' => $employee->full_name,
                    'employee_number' => $employee->employee_number,
                    'photo_url' => $employee->photo_path ? asset('storage/'.$employee->photo_path) : null,
                    'position' => $employee->currentAssignment?->position?->title_en,
                    'organization' => $employee->currentAssignment?->organization?->name_en,
                    'organization_unit' => $employee->currentAssignment?->organizationUnit?->name_en,
                ];
            }

            $cardNumber = $transaction->idCard?->card_number;
        }

        return redirect()->route($scanRoute)->with([
            'scan_result' => [
                'allowed' => true,
                'is_extra_scan' => $isExtraScan,
                'denial_reason' => null,
                'employee' => $employeeData,
                'card_number' => $cardNumber,
                'usage_mode' => $result['usage_mode'],
                'subsidy_applied' => $result['subsidy_applied'],
                'employee_payable' => $result['employee_payable'],
                'available_days_count' => $result['available_days_count'],
                'consumed_days_count' => $result['consumed_days_count'],
                'remaining_after' => $result['remaining_after'],
                'week_start' => $result['week_start'],
                'week_end' => $result['week_end'],
                'consumed_dates' => $result['consumed_dates'] ?? [],
                'transaction_id' => $transaction?->id,
                'employee_id' => $transaction?->employee_id,
                'duplicate' => $result['duplicate'] ?? false,
                'calendar_days' => $transaction?->employee
                    ? $this->calendarService->getEmployeeWeekCalendar($transaction->employee, $scannedAt, $provider)
                    : [],
            ],
            'flash' => [
                'message' => ($result['duplicate'] ?? false) ? __('cafeteria.scanRequestAlreadyProcessed') : __($messageKey),
                'type' => 'success',
            ],
        ]);
    }

    public function reverse(ReverseCafeteriaTransactionRequest $request, CafeteriaTransaction $cafeteriaTransaction, ReverseCafeteriaTransactionAction $action): RedirectResponse
    {
        $action->execute($cafeteriaTransaction, $request->user(), $request->string('reason')->toString() ?: null, $request);

        return back()->with('flash', ['message' => __('cafeteria.transactionReversed'), 'type' => 'success']);
    }

    private function todayScansForProvider(Request $request, string $providerId)
    {
        $query = CafeteriaTransaction::query()
            ->with(['employee.currentAssignment.organization', 'employee.currentAssignment.organizationUnit', 'employee.currentAssignment.position', 'provider', 'consumedDays'])
            ->where('cafeteria_provider_id', $providerId)
            ->whereDate('transaction_date', Carbon::today())
            ->orderByDesc('scanned_at')
            ->limit(50);

        $this->providerAccess->filterProviderScopedQuery($request->user(), $query);

        return $query->get();
    }
}
