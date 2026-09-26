<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Audit\WriteAuditLogAction;
use App\Actions\Cafeteria\ProcessCafeteriaQrScanAction;
use App\Actions\Cafeteria\ReverseCafeteriaTransactionAction;
use App\Enums\AuditEventType;
use App\Enums\CafeteriaTransactionStatus;
use App\Exports\Cafeteria\TransactionStatementExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\ProcessCafeteriaQrScanRequest;
use App\Http\Requests\ReverseCafeteriaTransactionRequest;
use App\Http\Resources\CafeteriaTransactionResource;
use App\Models\CafeteriaProvider;
use App\Models\CafeteriaTransaction;
use App\Models\Employee;
use App\Services\Cafeteria\CafeteriaCalendarService;
use App\Services\Cafeteria\CafeteriaProviderAccessService;
use App\Services\Cafeteria\CafeteriaSettingsService;
use App\Services\Cafeteria\TransactionStatementService;
use App\Services\Calendar\CalendarService;
use App\Services\Nfc\NfcCredentialService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;

class CafeteriaTransactionController extends Controller
{
    public function __construct(
        private readonly CafeteriaProviderAccessService $providerAccess,
        private readonly CafeteriaCalendarService $calendarService,
    ) {}

    public function index(Request $request, TransactionStatementService $statement): Response
    {
        $this->authorize('viewAny', CafeteriaTransaction::class);

        $filters = $statement->filters($request);
        $query = $statement->query($request->user(), $filters)
            ->with(['employee.currentAssignment.organization', 'employee.currentAssignment.organizationUnit', 'employee.currentAssignment.position', 'provider', 'consumedDays'])
            ->orderByDesc('scanned_at');

        $summary = $statement->summary($query);
        $transactions = $query->paginate(30)->withQueryString();

        $providers = CafeteriaProvider::query()
            ->with('organization:id,name_en,name_am,code')
            ->where('is_active', true)
            ->when(! $this->providerAccess->canAccessAllProviders($request->user()), function ($query) use ($request): void {
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
            'filters' => $filters,
            'summary' => $summary,
            'providers' => $providers,
            'can' => [
                'scan' => $request->user()?->can('scan', CafeteriaTransaction::class) ?? false,
                'export' => $request->user()?->can('export', CafeteriaTransaction::class) ?? false,
            ],
        ]);
    }

    public function exportStatement(Request $request, string $format, TransactionStatementService $statement, CalendarService $calendar): \Symfony\Component\HttpFoundation\Response
    {
        $this->authorize('export', CafeteriaTransaction::class);

        // The statement is rendered server-side, so the reader's locale has to be
        // applied before any __() call or date is formatted.
        $locale = $this->exportLocale($request);
        app()->setLocale($locale);

        $filters = $statement->filters($request);
        $query = $statement->query($request->user(), $filters);
        $transactions = $query->with(['employee', 'provider'])->orderBy('transaction_date')->orderBy('scanned_at')->get();
        // Summarize the exact exported snapshot, including every page.
        $accepted = $transactions->where('status', CafeteriaTransactionStatus::Accepted);
        $summary = ['total' => $transactions->count(), 'accepted' => $accepted->count(),
            'meals' => $accepted->sum('meal_amount'), 'subsidy' => $accepted->sum('subsidy_amount_applied'),
            'employee_payable' => $accepted->sum('employee_payable_amount'), 'deductions' => $accepted->sum('deduction_amount')];
        $provider = $filters['provider_id'] ? CafeteriaProvider::find($filters['provider_id']) : null;
        if ($provider) {
            abort_unless($this->providerAccess->canAccessProvider($request->user(), $provider), 403);
        }
        // Both renderers read the same pre-localized rows so PDF, print and XLSX
        // never drift apart on names, dates or status labels.
        $rows = $transactions->map(fn (CafeteriaTransaction $txn): array => [
            'number' => $txn->transaction_number,
            'date' => $calendar->formatDate($txn->transaction_date, $locale) ?? $txn->transaction_date?->toDateString() ?? '',
            'employee_name' => $txn->employee?->full_name ?? '',
            'employee_number' => $txn->employee?->employee_number ?? '',
            'provider' => $this->localizedProviderName($txn->provider, $locale) ?? '',
            'status' => $this->localizedStatus($txn->status?->value),
            'meal_amount' => (float) $txn->meal_amount,
            'subsidy_amount_applied' => (float) $txn->subsidy_amount_applied,
            'employee_payable_amount' => (float) $txn->employee_payable_amount,
            'deduction_amount' => (float) $txn->deduction_amount,
        ])->all();
        $periodLabel = $filters['start_date']
            ? ($calendar->formatDate($filters['start_date'], $locale) ?? $filters['start_date']).' — '.($calendar->formatDate($filters['end_date'], $locale) ?? $filters['end_date'])
            : __('cafeteria-statement.all_time');
        $data = ['transactions' => $transactions, 'rows' => $rows, 'summary' => $summary, 'filters' => $filters,
            'locale' => $locale,
            'statusLabel' => $filters['status'] ? $this->localizedStatus($filters['status']) : __('cafeteria-statement.all_statuses'),
            'providerName' => $this->localizedProviderName($provider, $locale) ?? __('cafeteria-statement.all_providers'),
            'actor' => $request->user()->name,
            'generatedAt' => $calendar->formatDateTime(now(), $locale) ?? now()->format('Y-m-d H:i'),
            'periodLabel' => $periodLabel];
        $filename = 'cafeteria-statement-'.($filters['start_date'] ?? 'all').'-'.($filters['end_date'] ?? now()->toDateString());

        app(WriteAuditLogAction::class)->execute(
            AuditEventType::CafeteriaProviderPaymentClaimExported,
            $request->user(), $provider, $provider?->organization_id, null,
            ['format' => $format, 'filters' => $filters, 'summary' => $summary], null, $request,
        );

        if ($format === 'xlsx') {
            return Excel::download(new TransactionStatementExport($data), $filename.'.xlsx');
        }

        $pdf = Pdf::loadView('cafeteria.exports.transaction-statement', $data)->setPaper('a4', 'landscape');

        return $format === 'print' ? $pdf->stream($filename.'.pdf') : $pdf->download($filename.'.pdf');
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
            'scanOptions' => app(CafeteriaSettingsService::class)->scanOptions(),
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
            'scanOptions' => app(CafeteriaSettingsService::class)->scanOptions(),
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

    public function processScan(ProcessCafeteriaQrScanRequest $request, ProcessCafeteriaQrScanAction $action, NfcCredentialService $nfcCredentials): RedirectResponse
    {
        $provider = CafeteriaProvider::findOrFail($request->validated('provider_id'));

        abort_unless($this->providerAccess->canAccessProvider($request->user(), $provider), 403, __('cafeteria.providerAccessDenied'));

        // Server time only: a client-chosen time could claim past entitlement days.
        $scannedAt = Carbon::now();

        // An NFC tap resolves to the same ID card a QR scan would produce, and
        // is then handed to the same action - one set of cafeteria rules for
        // both credentials. A credential that is not active never reaches the
        // scan service; it is denied here with its own reason code.
        $credential = $request->validated('nfc_credential');
        $scanInput = $request->validated('qr_token');

        if ($credential !== null) {
            $resolved = $nfcCredentials->resolveForAttendedScan($credential);

            if ($resolved['card'] === null) {
                return redirect()->route($request->validated('source') === 'mobile' ? 'cafeteria.scan.mobile' : 'cafeteria.scan')
                    ->with(['scan_result' => [
                        'allowed' => false,
                        'is_extra_scan' => false,
                        'denial_reason' => $resolved['reason'],
                        'employee' => null,
                        'credential_method' => 'nfc',
                    ]]);
            }

            $scanInput = $resolved['card'];
        }

        $result = $action->execute(
            $scanInput,
            $provider,
            $scannedAt,
            $request->user(),
            $request,
            [
                'usage_mode' => $request->validated('usage_mode'),
                'scan_nonce' => $request->validated('scan_nonce'),
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

    /**
     * Resolve the locale the statement is rendered in.
     * Priority: request query `locale` > session `locale` > app default, limited to supported locales.
     */
    private function exportLocale(Request $request): string
    {
        $supported = ['en', 'am'];
        $fromRequest = $request->query('locale');
        if (is_string($fromRequest) && in_array($fromRequest, $supported, true)) {
            return $fromRequest;
        }
        $fromSession = session('locale');
        if (is_string($fromSession) && in_array($fromSession, $supported, true)) {
            return $fromSession;
        }

        return in_array(app()->getLocale(), $supported, true) ? app()->getLocale() : 'en';
    }

    private function localizedProviderName(?CafeteriaProvider $provider, string $locale): ?string
    {
        if ($provider === null) {
            return null;
        }

        return $locale === 'am' ? ($provider->name_am ?: $provider->name_en) : $provider->name_en;
    }

    /** Translate a transaction status, falling back to the raw value when no translation exists. */
    private function localizedStatus(?string $status): string
    {
        if ($status === null || $status === '') {
            return '';
        }
        $key = 'provider-portal.status_'.$status;
        $translated = __($key);

        return $translated !== $key ? (string) $translated : $status;
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
