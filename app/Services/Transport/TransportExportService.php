<?php

declare(strict_types=1);

namespace App\Services\Transport;

use App\Exports\Transport\TransportTabularExport;
use App\Models\TransportRoute;
use App\Models\TransportSettlement;
use App\Models\TransportTransaction;
use App\Models\TransportTrip;
use App\Services\Calendar\CalendarService;
use App\Services\Transport\Settlement\TransportSettlementService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exports in the viewer's language: Amharic labels with Ethiopian dates,
 * English labels with Gregorian dates. CSV streams in chunks with a UTF-8
 * BOM and formula-safe cells; XLSX and PDF use the shared report typography
 * (Ethiopic fonts). Nothing here widens what the caller may see: the query
 * it receives is already scoped.
 */
class TransportExportService
{
    public function __construct(
        private readonly CalendarService $calendar,
        private readonly TransportSettlementService $settlements,
    ) {}

    /** The export locale: the `locale` query, then the session, then the app; English otherwise. */
    public function locale(Request $request): string
    {
        foreach ([$request->query('locale'), session('locale'), app()->getLocale()] as $candidate) {
            if (is_string($candidate) && in_array($candidate, ['en', 'am'], true)) {
                return $candidate;
            }
        }

        return 'en';
    }

    /**
     * Boardings as CSV: one row per scan, staff tier adds the organization.
     *
     * @param  Builder<TransportTransaction>  $query
     */
    public function boardingsCsv(Builder $query, string $locale, bool $staffTier, string $filename = 'transport-boardings.csv'): StreamedResponse
    {
        $headers = $this->headers(['date', 'time', 'employee_number', 'employee_name', ...($staffTier ? ['organization'] : []), 'provider', 'route', 'direction', 'trip', 'vehicle', 'stop', 'method', 'status', 'result'], $locale);

        return $this->streamCsv($filename, $headers, function () use ($query, $locale, $staffTier): \Generator {
            $query->with(['employee.currentAssignment.organization', 'provider', 'route', 'trip', 'vehicle', 'stop']);
            foreach ($query->lazyById(500, 'transport_transactions.id') as $t) {
                yield [
                    $this->date($t->transaction_date, $locale),
                    $t->scanned_at?->format('H:i'),
                    $t->employee?->employee_number,
                    $t->employee?->full_name,
                    ...($staffTier ? [$this->name($t->employee?->currentAssignment?->organization, $locale)] : []),
                    $this->name($t->provider, $locale),
                    $t->route ? $t->route->route_code.' '.$this->name($t->route, $locale) : null,
                    $t->direction?->value !== null ? __('transport.directions.'.$t->direction->value, [], $locale) : null,
                    $t->trip?->trip_number,
                    $t->vehicle?->plate_number,
                    $t->stop ? $this->name($t->stop, $locale) : null,
                    $t->verification_method !== null ? __('transport.methods.'.$t->verification_method->value, [], $locale) : null,
                    __('transport.statuses.'.($t->voided_at !== null ? 'voided' : $t->status), [], $locale),
                    $t->result_code !== null ? __('transport.reasons.'.$t->result_code, [], $locale) : null,
                ];
            }
        });
    }

    /**
     * Any tabular report (route utilization, daily usage, …) in CSV or XLSX.
     *
     * @param  list<string>  $columns  keys into transport.export.columns
     * @param  list<array<string, mixed>>  $rows
     */
    public function table(string $format, string $title, array $columns, array $rows, string $locale, string $basename): StreamedResponse|BinaryFileResponse
    {
        $headers = $this->headers($columns, $locale);
        $values = array_map(fn (array $row): array => array_map(fn (string $column) => $this->cell($row[$column] ?? null, $column, $locale), $columns), $rows);

        if ($format === 'xlsx') {
            return Excel::download(new TransportTabularExport(__($title, [], $locale), $headers, $values), $basename.'.xlsx');
        }

        return $this->streamCsv($basename.'.csv', $headers, function () use ($values): \Generator {
            yield from $values;
        });
    }

    public function settlementPdf(TransportSettlement $settlement, string $locale, bool $download = true): Response
    {
        $settlement->loadMissing(['provider', 'contract', 'invoice', 'organization', 'adjustments', 'computedBy', 'reviewedBy', 'approvedBy']);
        app()->setLocale($locale);
        $routes = TransportRoute::query()->whereIn('id', collect($settlement->basis['by_route'] ?? [])->pluck('route_id')->all())->get(['id', 'route_code', 'name_en', 'name_am'])->keyBy('id');

        $pdf = Pdf::loadView('transport.exports.settlement-statement', [
            'settlement' => $settlement,
            'reconciliation' => $this->settlements->reconciliation($settlement),
            'routes' => $routes,
            'locale' => $locale,
            'generatedAt' => $this->calendar->formatDateTime(now(), $locale),
            'periodStart' => $this->date($settlement->period_start, $locale),
            'periodEnd' => $this->date($settlement->period_end, $locale),
            'calendar' => $this->calendar,
        ])->setPaper('a4', 'portrait');

        $name = 'transport-settlement-'.$settlement->settlement_number.'.pdf';

        return $download ? $pdf->download($name) : $pdf->stream($name);
    }

    /** @param  array{trip: TransportTrip, rows: list<array<string, mixed>>, totals: array<string, int>}  $manifest */
    public function manifestPdf(array $manifest, string $locale, bool $staffTier): Response
    {
        app()->setLocale($locale);

        return Pdf::loadView('transport.exports.manifest', [
            'trip' => $manifest['trip'],
            'rows' => $manifest['rows'],
            'totals' => $manifest['totals'],
            'staffTier' => $staffTier,
            'locale' => $locale,
            'tripDate' => $this->date($manifest['trip']->trip_date, $locale),
            'generatedAt' => $this->calendar->formatDateTime(now(), $locale),
        ])->setPaper('a4', 'portrait')->download('transport-manifest-'.$manifest['trip']->trip_number.'.pdf');
    }

    /** @param  list<string>  $columns @return list<string> */
    private function headers(array $columns, string $locale): array
    {
        return array_map(fn (string $column): string => (string) __('transport.export.columns.'.$column, [], $locale), $columns);
    }

    private function cell(mixed $value, string $column, string $locale): mixed
    {
        if ($value === null) {
            return null;
        }
        if ($column === 'date' || str_ends_with($column, '_date')) {
            return $this->date($value, $locale);
        }
        if ($column === 'direction') {
            return __('transport.directions.'.$value, [], $locale);
        }
        if ($column === 'status' || $column === 'result') {
            return trans()->has('transport.statuses.'.$value, $locale) ? __('transport.statuses.'.$value, [], $locale) : $value;
        }

        return $value;
    }

    private function date(Carbon|string|null $value, string $locale): ?string
    {
        return $value === null ? null : $this->calendar->formatDate($value, $locale);
    }

    private function name(?object $named, string $locale): ?string
    {
        if ($named === null) {
            return null;
        }

        return $locale === 'am' ? ($named->name_am ?: $named->name_en) : ($named->name_en ?: $named->name_am);
    }

    /** @param  list<string>  $headers @param  callable(): iterable<array<int, mixed>>  $rows */
    private function streamCsv(string $filename, array $headers, callable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, csv_safe_row($headers));
            foreach ($rows() as $row) {
                fputcsv($out, csv_safe_row(array_map(fn ($v) => $v === null ? '' : (string) $v, $row)));
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
