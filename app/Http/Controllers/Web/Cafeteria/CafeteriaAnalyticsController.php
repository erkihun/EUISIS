<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Cafeteria;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Cafeteria\Concerns\CafeteriaAdminContext;
use App\Services\Cafeteria\Reports\CafeteriaAnalyticsReportService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Cafeteria Management → Analytics Reports. */
class CafeteriaAnalyticsController extends Controller
{
    use CafeteriaAdminContext;

    public function __construct(private readonly CafeteriaAnalyticsReportService $reports) {}

    public function index(Request $request): Response
    {
        $filters = $this->filters($request);

        return Inertia::render('Cafeteria/Analytics/Index', [
            'filters' => $filters,
            'types' => CafeteriaAnalyticsReportService::TYPES,
            'report' => $this->reports->run($filters['type'], $filters, $this->scopedOrganizationIds($request->user())),
            'organizations' => $this->organizationOptions($request->user()),
            'providers' => $this->providerOptions(),
            'networks' => $this->networkOptions(),
            'cafeterias' => $this->cafeteriaOptions(),
            'can' => ['export' => $request->user()->can('cafeteria_transactions.export')],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $report = $this->reports->run($filters['type'], $filters, $this->scopedOrganizationIds($request->user()));

        return response()->streamDownload(function () use ($report): void {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $report['columns']);
            foreach ($report['rows'] as $row) {
                fputcsv($out, csv_safe_row(array_map(fn (string $column) => $row[$column] ?? '', $report['columns'])));
            }
            fclose($out);
        }, 'cafeteria-'.$filters['type'].'-'.$filters['date_from'].'-'.$filters['date_to'].'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        $data = $request->validate([
            'type' => ['nullable', Rule::in(CafeteriaAnalyticsReportService::TYPES)],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'organization_id' => ['nullable', 'uuid'],
            'provider_id' => ['nullable', 'uuid'],
            'network_id' => ['nullable', 'uuid'],
            'cafeteria_id' => ['nullable', 'uuid'],
        ]);

        if (filled($data['organization_id'] ?? null)) {
            $this->assertOrganizationInScope($request->user(), $data['organization_id']);
        }

        return [
            'type' => $data['type'] ?? 'daily_transactions',
            'date_from' => $data['date_from'] ?? today()->startOfMonth()->toDateString(),
            'date_to' => $data['date_to'] ?? today()->toDateString(),
            'organization_id' => $data['organization_id'] ?? null,
            'provider_id' => $data['provider_id'] ?? null,
            'network_id' => $data['network_id'] ?? null,
            'cafeteria_id' => $data['cafeteria_id'] ?? null,
        ];
    }
}
