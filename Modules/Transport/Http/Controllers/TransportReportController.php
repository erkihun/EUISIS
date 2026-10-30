<?php

declare(strict_types=1);

namespace Modules\Transport\Http\Controllers;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Providers\Contracts\ProviderDirectory;
use Modules\Transport\Application\Services\TransportExportService;
use Modules\Transport\Application\Services\TransportReportService;
use Modules\Transport\Domain\Models\TransportRoute;
use Modules\Transport\Http\Requests\TransportReportRequest;

/** The admin reports, scoped to the viewer: boardings, daily usage, route and vehicle utilization, provider delivery. */
class TransportReportController extends Controller
{
    public const TYPES = ['daily_usage', 'route_utilization', 'vehicle_utilization', 'provider_performance'];

    public function index(TransportReportRequest $request, TransportReportService $reports): Response
    {
        $user = $request->user();
        $filters = $reports->normaliseFilters($request->validated());

        return Inertia::render('Transport/Reports/Index', [
            'report' => $reports->report($reports->forAdmin($user, $filters), byProvider: true),
            'dailyUsage' => $reports->dailyUsage($filters, null, $user),
            'routeUtilization' => $reports->routeUtilization($filters, null, $user),
            'vehicleUtilization' => $reports->vehicleUtilization($filters, null),
            'providerPerformance' => $reports->providerPerformance($filters, $user),
            'filters' => $filters,
            'providers' => app(ProviderDirectory::class)->offering('transport'),
            'routes' => TransportRoute::query()->where('is_active', true)->orderBy('route_code')->get(['id', 'provider_id', 'route_code', 'name_en', 'name_am']),
            'can' => ['viewTransactions' => $user->can('transport-transactions.viewAny'), 'export' => $user->can('transport-reports.export')],
        ]);
    }

    public function export(TransportReportRequest $request, string $type, string $format, TransportReportService $reports, TransportExportService $exports)
    {
        $user = $request->user();
        abort_unless($user?->can('transport-reports.export'), 403);
        abort_unless(in_array($type, self::TYPES, true) && in_array($format, ['csv', 'xlsx'], true), 404);
        $filters = $reports->normaliseFilters($request->validated());
        $locale = $exports->locale($request);

        [$columns, $rows] = match ($type) {
            'daily_usage' => [['date', 'assigned_employees', 'unique_riders', 'morning_riders', 'evening_riders', 'boardings', 'completed_trips', 'vehicles_used'], $reports->dailyUsage($filters, null, $user)],
            'route_utilization' => [['route_code', 'name_en', 'name_am', 'assigned_employees', 'unique_riders', 'boardings', 'inbound', 'outbound', 'scheduled_trips', 'completed_trips', 'cancelled_trips', 'seats_offered', 'utilization_percent', 'no_show_rate_percent'], $reports->routeUtilization($filters, null, $user)],
            'vehicle_utilization' => [['vehicle_code', 'plate_number', 'capacity', 'trips', 'completed_trips', 'passengers', 'seats_offered', 'utilization_percent', 'days_operated', 'distance_km'], $reports->vehicleUtilization($filters, null)],
            'provider_performance' => [['provider_code', 'name_en', 'name_am', 'scheduled_trips', 'completed_trips', 'cancelled_trips', 'completion_rate_percent', 'verified_boardings', 'unique_riders', 'utilization_percent', 'exceptions'], $reports->providerPerformance($filters, $user)],
        };

        return $exports->table($format, 'transport.export.titles.'.$type, $columns, $rows, $locale, 'transport-'.str_replace('_', '-', $type).'-'.$filters['date_from'].'-'.$filters['date_to']);
    }
}
