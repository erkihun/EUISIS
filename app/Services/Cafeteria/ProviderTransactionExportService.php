<?php

declare(strict_types=1);

namespace App\Services\Cafeteria;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\CafeteriaTransactionStatus;
use App\Exports\Cafeteria\ProviderPaymentClaimExport;
use App\Exports\Cafeteria\ProviderTransactionsExport;
use App\Models\CafeteriaProvider;
use App\Models\CafeteriaTransaction;
use App\Models\ProviderUser;
use App\Models\User;
use App\Services\Calendar\CalendarService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final readonly class ProviderTransactionExportService
{
    public function __construct(
        private ProviderPaymentClaimService $paymentClaimService,
        private WriteAuditLogAction $writeAuditLog,
        private CalendarService $calendar,
    ) {}

    /** @return Builder<CafeteriaTransaction> */
    public function query(CafeteriaProvider $provider, array $filters): Builder
    {
        $period = $this->paymentClaimService->period($filters);

        return CafeteriaTransaction::query()
            ->with([
                'employee.currentAssignment.organization',
                'employee.currentAssignment.organizationUnit',
                'employee.currentAssignment.position',
                'provider.organization',
                'operator:id,name',
                'consumedDays',
                'foodOrder',
            ])
            ->where('cafeteria_provider_id', $provider->id)
            ->whereBetween('transaction_date', [$period['start'], $period['end']])
            ->when((string) ($filters['status'] ?? '') !== '', fn (Builder $query): Builder => $query->where('status', $filters['status']))
            ->when((string) ($filters['usage_mode'] ?? '') !== '', fn (Builder $query): Builder => $query->where('usage_mode', $filters['usage_mode']))
            ->when((string) ($filters['transaction_type'] ?? '') !== '', fn (Builder $query): Builder => $query->where('transaction_type', $filters['transaction_type']))
            ->when($this->truthy($filters['subsidy_only'] ?? null), fn (Builder $query): Builder => $query->where('subsidy_amount_applied', '>', 0))
            ->when($this->truthy($filters['employee_payable'] ?? null), fn (Builder $query): Builder => $query->where('employee_payable_amount', '>', 0))
            ->when((string) ($filters['employee_search'] ?? '') !== '', function (Builder $query) use ($filters): Builder {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], (string) $filters['employee_search']).'%';

                return $query->whereHas('employee', function (Builder $employeeQuery) use ($term): void {
                    $employeeQuery->where('employee_number', ci_like_operator(), $term)
                        ->orWhere('full_name', ci_like_operator(), $term);
                });
            })
            ->when((string) ($filters['order_id'] ?? '') !== '', fn (Builder $query): Builder => $query->whereHas('foodOrder', fn (Builder $orderQuery): Builder => $orderQuery->whereKey($filters['order_id'])))
            ->when((string) ($filters['menu_id'] ?? '') !== '', fn (Builder $query): Builder => $query->whereHas('foodOrder', fn (Builder $orderQuery): Builder => $orderQuery->where('cafeteria_menu_id', $filters['menu_id'])))
            ->orderBy('transaction_date')
            ->orderBy('scanned_at');
    }

    public function csv(CafeteriaProvider $provider, array $filters, User|ProviderUser $actor, Request $request, bool $paymentClaim = false): StreamedResponse
    {
        $locale = $this->resolveLocale($request);
        app()->setLocale($locale);

        $data = $this->exportData($provider, $filters);
        $rows = $this->localizeRows($data['rows']);
        $summaryRows = $paymentClaim ? $this->paymentClaimSummaryRows($data['summary'], $actor) : [];
        $emptyMsg = __('provider-portal.no_transactions_found_for_export');
        $filename = $this->filename($paymentClaim ? 'cafeteria-payment-claim' : 'cafeteria-transactions', $provider, $data['summary']['period_start'], $data['summary']['period_end'], 'csv');

        $this->audit($provider, $actor, $request, $filters, $data['summary'], $data['row_count'], $paymentClaim, 'csv');

        return response()->streamDownload(function () use ($data, $rows, $summaryRows, $paymentClaim, $emptyMsg): void {
            $handle = fopen('php://output', 'wb');
            fwrite($handle, "\xEF\xBB\xBF");

            if ($paymentClaim) {
                foreach ($summaryRows as $summaryRow) {
                    fputcsv($handle, $summaryRow);
                }
                fputcsv($handle, []);
            }

            fputcsv($handle, $data['headers']);

            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }

            if (count($rows) === 0) {
                fputcsv($handle, [$emptyMsg]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function xlsx(CafeteriaProvider $provider, array $filters, User|ProviderUser $actor, Request $request, bool $paymentClaim = false): Response
    {
        $locale = $this->resolveLocale($request);
        app()->setLocale($locale);

        $data = $this->exportData($provider, $filters);
        $rows = $this->localizeRows($data['rows']);
        $filename = $this->filename($paymentClaim ? 'cafeteria-payment-claim' : 'cafeteria-transactions', $provider, $data['summary']['period_start'], $data['summary']['period_end'], 'xlsx');

        $this->audit($provider, $actor, $request, $filters, $data['summary'], $data['row_count'], $paymentClaim, 'xlsx');

        $export = $paymentClaim
            ? new ProviderPaymentClaimExport($this->paymentClaimSummaryRows($data['summary'], $actor), $data['headers'], $rows)
            : new ProviderTransactionsExport($data['headers'], $rows);

        return Excel::download($export, $filename);
    }

    public function pdf(CafeteriaProvider $provider, array $filters, User|ProviderUser $actor, Request $request, bool $paymentClaim = false): Response
    {
        $locale = $this->resolveLocale($request);
        app()->setLocale($locale);

        $data = $this->exportData($provider, $filters);

        $filename = $this->filename($paymentClaim ? 'cafeteria-payment-claim' : 'cafeteria-transactions', $provider, $data['summary']['period_start'], $data['summary']['period_end'], 'pdf');

        $this->audit($provider, $actor, $request, $filters, $data['summary'], $data['row_count'], $paymentClaim, 'pdf');

        return Pdf::loadView('cafeteria.exports.provider-transactions', [
            'actor' => $actor,
            'headers' => $data['headers'],
            'paymentClaim' => $paymentClaim,
            'rows' => $data['rows'],
            'pdfRows' => $data['rows'],   // alias used in the PDF template
            'summary' => $data['summary'],
            'periodStart' => $this->calendar->formatDate($data['summary']['period_start'], $locale) ?? $data['summary']['period_start'],
            'periodEnd' => $this->calendar->formatDate($data['summary']['period_end'], $locale) ?? $data['summary']['period_end'],
            'generatedAt' => $this->calendar->formatDateTime(Carbon::now(), $locale) ?? Carbon::now()->toDateTimeString(),
        ])
            ->setPaper('a4', 'landscape')
            ->download($filename);
    }

    /** @return array<string, mixed> */
    public function summaryPayload(CafeteriaProvider $provider, array $filters): array
    {
        return $this->paymentClaimService->summary($provider, $this->query($provider, $filters), $filters);
    }

    /** @return array<string> */
    private function headers(): array
    {
        return [
            __('provider-portal.export_columns.transaction_number'),
            __('provider-portal.export_columns.date_gregorian'),
            __('provider-portal.export_columns.date_display'),
            __('provider-portal.export_columns.scan_time'),
            __('provider-portal.export_columns.employee_number'),
            __('provider-portal.export_columns.employee_name'),
            __('provider-portal.export_columns.employee_institution'),
            __('provider-portal.export_columns.employee_organization_unit'),
            __('provider-portal.export_columns.position'),
            __('provider-portal.export_columns.cafeteria_provider'),
            __('provider-portal.export_columns.provider_institution'),
            __('provider-portal.export_columns.usage_mode'),
            __('provider-portal.export_columns.consumed_days_count'),
            __('provider-portal.export_columns.consumed_dates'),
            __('provider-portal.export_columns.subsidy_amount_applied'),
            __('provider-portal.export_columns.employee_payable_amount'),
            __('provider-portal.export_columns.transaction_status'),
            __('provider-portal.export_columns.rejection_reason'),
            __('provider-portal.export_columns.operator'),
            __('provider-portal.export_columns.created_at'),
        ];
    }

    /** @return array<int, mixed> */
    private function row(CafeteriaTransaction $transaction): array
    {
        $employee = $transaction->employee;
        $assignment = $employee?->currentAssignment;
        $locale = app()->getLocale(); // set by resolveLocale() at export-method entry
        $dateGreg = $transaction->transaction_date?->toDateString();
        $dateDisplay = $this->calendar->formatDate($transaction->transaction_date, $locale);
        $scannedDisplay = $this->calendar->formatDateTime($transaction->scanned_at, $locale);
        $createdDisplay = $this->calendar->formatDateTime($transaction->created_at, $locale);

        $consumedDates = $transaction->consumedDays
            ->whereNull('reversed_at')
            ->pluck('consumed_date')
            ->map(fn ($d) => $d ? $this->calendar->formatDate($d, $locale) : null)
            ->filter()
            ->implode(', ');

        return [
            $transaction->transaction_number,
            $dateGreg,
            $dateDisplay,
            $scannedDisplay,
            $employee?->employee_number,
            $employee?->full_name,
            $assignment?->organization?->name_en,
            $assignment?->organizationUnit?->name_en,
            $assignment?->position?->title_en,
            $transaction->provider?->name_en,
            $transaction->provider?->organization?->name_en,
            $this->localizedUsageMode($transaction->usage_mode?->value),
            (int) $transaction->consumed_days_count,
            $consumedDates,
            number_format((float) $transaction->subsidy_amount_applied, 2, '.', ''),
            number_format((float) $transaction->employee_payable_amount, 2, '.', ''),
            $transaction->status?->value,  // raw; CSV/XLSX post-processed; PDF template translates via trans()
            $transaction->status === CafeteriaTransactionStatus::Rejected ? $transaction->blocked_reason : null,
            $transaction->operator?->name,
            $createdDisplay,
        ];
    }

    /** @return array<int, array<int, mixed>> */
    private function paymentClaimSummaryRows(array $summary, User|ProviderUser $actor): array
    {
        $locale = app()->getLocale(); // set by resolveLocale() at export-method entry
        $periodStart = $this->calendar->formatDate($summary['period_start'], $locale) ?? $summary['period_start'];
        $periodEnd = $this->calendar->formatDate($summary['period_end'], $locale) ?? $summary['period_end'];
        $generatedAt = $this->calendar->formatDateTime(Carbon::now(), $locale) ?? Carbon::now()->toDateTimeString();

        return [
            [__('provider-portal.payment_claim')],
            [__('provider-portal.provider'), $summary['provider_name']],
            [__('provider-portal.assigned_institution'), $summary['assigned_institution']],
            [__('provider-portal.claim_period'), $periodStart.' - '.$periodEnd],
            [__('provider-portal.generated_by'), $actor->name],
            [__('provider-portal.generated_at'), $generatedAt],
            [__('provider-portal.accepted_transactions'), $summary['accepted_transactions']],
            [__('provider-portal.rejected_transactions'), $summary['rejected_transactions']],
            [__('provider-portal.total_subsidy_payable'), $summary['total_subsidy_payable']],
            [__('provider-portal.employee_payable_amount'), $summary['total_employee_payable']],
            [__('provider-portal.reversals_and_adjustments'), $summary['reversal_amount']],
            [__('provider-portal.net_payable_amount'), $summary['net_payable_amount']],
        ];
    }

    private function filename(string $prefix, CafeteriaProvider $provider, string $startDate, string $endDate, string $extension): string
    {
        $code = preg_replace('/[^A-Za-z0-9_-]+/', '-', $provider->code) ?: 'provider';

        return strtolower($prefix.'-'.$code.'-'.$startDate.'-'.$endDate.'.'.$extension);
    }

    private function audit(CafeteriaProvider $provider, User|ProviderUser $actor, Request $request, array $filters, array $summary, int $rowCount, bool $paymentClaim, string $format): void
    {
        $this->writeAuditLog->execute(
            $paymentClaim ? AuditEventType::CafeteriaProviderPaymentClaimExported : AuditEventType::CafeteriaProviderTransactionsExported,
            $actor instanceof User ? $actor : null,
            $provider,
            $provider->organization_id,
            null,
            [
                'provider_id' => $provider->id,
                'provider_code' => $provider->code,
                'format' => $format,
                'period_start' => $summary['period_start'],
                'period_end' => $summary['period_end'],
                'filters' => $filters,
                'row_count' => $rowCount,
                'total_subsidy_amount' => $summary['total_subsidy_payable'],
                'net_payable_amount' => $summary['net_payable_amount'],
                'actor_id' => $actor->id,
                'actor_type' => $actor::class,
            ],
            null,
            $request,
        );
    }

    /** @return array{summary: array<string, mixed>, headers: array<int, string>, rows: array<int, array<int, mixed>>, row_count: int} */
    private function exportData(CafeteriaProvider $provider, array $filters): array
    {
        $query = $this->query($provider, $filters);
        $summary = $this->paymentClaimService->summary($provider, clone $query, $filters);
        $transactions = $query->get();
        $rows = $transactions
            ->map(fn (CafeteriaTransaction $transaction): array => $this->row($transaction))
            ->all();

        return [
            'summary' => $summary,
            'headers' => $this->headers(),
            'rows' => $rows,
            'row_count' => $transactions->count(),
        ];
    }

    /**
     * Resolve the export locale.
     * Priority: request query `locale` > session `locale` > app default > `en`.
     * Only allows locales that are plausibly supported (en / am).
     */
    private function resolveLocale(Request $request): string
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
        $appLocale = app()->getLocale();

        return in_array($appLocale, $supported, true) ? $appLocale : 'en';
    }

    /**
     * Translate a usage-mode enum value using the current export locale.
     * app()->setLocale() is always called before rows are built, so __() is reliable.
     */
    private function localizedUsageMode(?string $mode): ?string
    {
        if ($mode === null) {
            return null;
        }
        $key = "provider-portal.usage_{$mode}";
        $translated = __($key);

        // __() returns the key unchanged when not found — fall back to raw enum value
        return ($translated !== $key) ? (string) $translated : $mode;
    }

    /**
     * Post-process rows for CSV/XLSX: replace raw status (col 16) with a localized label.
     * NOT used for PDF rows — the Blade template handles status display with CSS class logic.
     *
     * @param  array<int, array<int, mixed>>  $rows
     * @return array<int, array<int, mixed>>
     */
    private function localizeRows(array $rows): array
    {
        return array_map(function (array $row): array {
            // Column 16 = status raw value
            $row[16] = $this->localizedStatus((string) ($row[16] ?? ''));

            return $row;
        }, $rows);
    }

    /**
     * Translate a transaction status enum value using the current export locale.
     * app()->setLocale() is always called before this runs, so __() is reliable.
     */
    private function localizedStatus(?string $status): ?string
    {
        if ($status === null) {
            return null;
        }
        $key = "provider-portal.status_{$status}";
        $translated = __($key);

        return ($translated !== $key) ? (string) $translated : $status;
    }

    private function truthy(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'true', 'on', 'yes'], true);
    }
}
