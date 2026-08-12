<?php

declare(strict_types=1);

namespace App\Http\Controllers\ProviderPortal;

use App\Actions\Cafeteria\ProcessCafeteriaQrScanAction;
use App\Http\Controllers\Controller;
use App\Http\Controllers\ProviderPortal\Concerns\FormatsProviderPortalData;
use App\Http\Requests\ProviderPortal\ProviderScanRequest;
use App\Http\Resources\CafeteriaTransactionResource;
use App\Models\CafeteriaProviderLedgerEntry;
use App\Models\CafeteriaTransaction;
use App\Models\Employee;
use App\Services\Cafeteria\CafeteriaCalendarService;
use App\Services\ProviderPortal\ProviderPortalContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class ProviderScanController extends Controller
{
    use FormatsProviderPortalData;

    public function __construct(private readonly CafeteriaCalendarService $calendarService) {}

    public function index(Request $request, ProviderPortalContext $context): Response
    {
        $provider = $context->selectedProvider($request);
        abort_if($provider === null, 403, __('provider-portal.not_assigned'));

        $todayScans = CafeteriaTransaction::query()
            ->with(['employee.currentAssignment.organization', 'employee.currentAssignment.position', 'provider', 'consumedDays'])
            ->where('cafeteria_provider_id', $provider->id)
            ->whereDate('transaction_date', Carbon::today())
            ->orderByDesc('scanned_at')
            ->limit(25)
            ->get();

        return Inertia::render('Cafeteria/Portal/Scan', [
            ...$this->portalPayload($request, $context, $provider),
            'today_scans' => CafeteriaTransactionResource::collection($todayScans)->resolve(),
            'scan_result' => $request->session()->get('provider_scan_result'),
        ]);
    }

    public function store(ProviderScanRequest $request, ProcessCafeteriaQrScanAction $action, ProviderPortalContext $context): RedirectResponse
    {
        $provider = $context->selectedProvider($request);
        abort_if($provider === null, 403, __('provider-portal.not_assigned'));

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
            ],
        );

        $transaction = $result['transaction'];
        if ($result['allowed'] && $transaction !== null && ! ($result['duplicate'] ?? false)) {
            CafeteriaProviderLedgerEntry::query()->create([
                'cafeteria_provider_id' => $provider->id,
                'cafeteria_transaction_id' => $transaction->id,
                'entry_date' => $transaction->transaction_date,
                'entry_type' => 'scan_subsidy',
                'debit' => 0,
                'credit' => $transaction->subsidy_amount_applied,
                'balance_after' => CafeteriaProviderLedgerEntry::query()
                    ->where('cafeteria_provider_id', $provider->id)
                    ->latest('created_at')
                    ->value('balance_after') + $transaction->subsidy_amount_applied,
                'description' => $transaction->transaction_number,
                'created_by' => $request->user()?->id,
            ]);
        }

        return back()->with([
            'provider_scan_result' => $this->scanResultPayload($result),
            'flash' => [
                'message' => $result['allowed']
                    ? __('provider-portal.scan_recorded')
                    : ($result['denial_message'] ?? __('provider-portal.scan_denied')),
                'type' => $result['allowed'] ? 'success' : 'error',
            ],
        ]);
    }

    public function today(Request $request, ProviderPortalContext $context): JsonResponse
    {
        $provider = $context->selectedProvider($request);
        abort_if($provider === null, 403);

        $scans = CafeteriaTransaction::query()
            ->with(['employee.currentAssignment.organization', 'employee.currentAssignment.organizationUnit', 'employee.currentAssignment.position', 'provider', 'consumedDays'])
            ->where('cafeteria_provider_id', $provider->id)
            ->whereDate('transaction_date', Carbon::today())
            ->orderByDesc('scanned_at')
            ->limit(50)
            ->get();

        return response()->json([
            'data' => CafeteriaTransactionResource::collection($scans)->resolve(),
        ]);
    }

    public function calendar(Request $request, ProviderPortalContext $context): JsonResponse
    {
        $provider = $context->selectedProvider($request);
        abort_if($provider === null, 403);

        $employee = $request->filled('employee_id')
            ? Employee::query()->find($request->string('employee_id')->toString())
            : null;

        $date = $request->filled('date')
            ? Carbon::parse($request->string('date')->toString())
            : Carbon::today();

        return response()->json([
            'calendar_days' => $this->calendarService->getEmployeeWeekCalendar($employee, $date, $provider),
        ]);
    }

    /** @param array<string, mixed> $result */
    private function scanResultPayload(array $result): array
    {
        $transaction = $result['transaction'];
        $employee = $transaction?->employee ?? ($result['employee'] ?? null);

        if ($transaction !== null) {
            $transaction->loadMissing('employee.currentAssignment.position', 'idCard');
            $employee = $transaction->employee;
        }

        return [
            'allowed' => (bool) $result['allowed'],
            'is_extra_scan' => (bool) ($result['is_extra_scan'] ?? false),
            'denial_reason' => $result['denial_reason'] ?? null,
            'denial_message' => $result['denial_message'] ?? null,
            'employee' => $employee ? [
                'full_name' => $employee->full_name,
                'employee_number' => $employee->employee_number,
                'position' => $employee->currentAssignment?->position?->title_en,
                'photo_url' => $employee->photo_path ? asset('storage/'.$employee->photo_path) : null,
            ] : null,
            'card_number' => $transaction?->idCard?->card_number ?? ($result['id_card'] ?? null)?->card_number,
            'card_status' => $transaction?->idCard?->status?->value ?? ($result['card_status'] ?? null),
            'subsidy_applied' => (float) ($result['subsidy_applied'] ?? 0),
            'employee_payable' => (float) ($result['employee_payable'] ?? 0),
            'remaining_after' => (float) ($result['remaining_after'] ?? 0),
            'transaction_id' => $transaction?->id,
        ];
    }
}
