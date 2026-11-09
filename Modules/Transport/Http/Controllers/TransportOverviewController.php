<?php

declare(strict_types=1);

namespace Modules\Transport\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Transport\Application\Services\TransportDataQualityService;
use Modules\Transport\Application\Services\TransportReportService;
use Modules\Transport\Application\Services\TransportSettings;
use Modules\Transport\Domain\Enums\TransportTripStatus;
use Modules\Transport\Domain\Models\TransportDevice;
use Modules\Transport\Domain\Models\TransportException;
use Modules\Transport\Domain\Models\TransportSettlement;
use Modules\Transport\Domain\Models\TransportTrip;

/** The transport desk: today's service, verified usage, open exceptions and data quality, within the viewer's scope. */
class TransportOverviewController extends Controller
{
    public function __invoke(Request $request, TransportReportService $reports, TransportDataQualityService $quality, TransportSettings $settings): Response
    {
        $user = $request->user();
        abort_unless($user?->can('transport-reports.view') || $user?->can('transport-trips.viewAny'), 403);

        $today = today()->toDateString();
        $filters = $reports->normaliseFilters(['date_from' => $today, 'date_to' => $today]);
        $todayReport = $reports->report($reports->forAdmin($user, $filters), true)['summary'];
        $month = $reports->normaliseFilters(['date_from' => today()->startOfMonth()->toDateString(), 'date_to' => $today]);

        $trips = TransportTrip::query()->whereDate('trip_date', $today)->toBase()
            ->select('status', DB::raw('COUNT(*) AS n'), DB::raw('SUM(boarded_count) AS boarded'))->groupBy('status')->get()->keyBy('status');
        $count = fn (string $status): int => (int) ($trips->get($status)->n ?? 0);

        return Inertia::render('Transport/Overview', [
            'today' => [
                'trips' => $trips->sum('n'),
                'completed' => $count(TransportTripStatus::Completed->value),
                'cancelled' => $count(TransportTripStatus::Cancelled->value) + $count(TransportTripStatus::Failed->value),
                'in_service' => $count(TransportTripStatus::Boarding->value) + $count(TransportTripStatus::InProgress->value),
                'verified_boardings' => $todayReport['verified'],
                'morning' => $todayReport['inbound'],
                'evening' => $todayReport['outbound'],
                'riders' => $todayReport['riders'],
                'rejected' => $todayReport['rejected'],
                'vehicles_active' => (int) TransportTrip::query()->whereDate('trip_date', $today)->whereIn('status', TransportTripStatus::boardable())->distinct()->count('transport_vehicle_id'),
                'open_exceptions' => (int) TransportException::query()->where('status', 'open')->count(),
                'stale_devices' => (int) TransportDevice::query()->where('status', 'active')->where(fn ($q) => $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<', now()->subMinutes($settings->deviceInactivityTimeoutMinutes())))->count(),
                'settlements_in_review' => (int) TransportSettlement::query()->where('status', 'under_review')->count(),
            ],
            'month' => [
                'filters' => $month,
                'daily' => $reports->dailyUsage($month, null, $user),
                'providers' => $reports->providerPerformance($month, $user),
            ],
            'dataQuality' => $quality->summary(),
            'can' => [
                'trips' => $user->can('transport-trips.viewAny'),
                'exceptions' => $user->can('transport-exceptions.viewAny'),
                'settlements' => $user->can('transport-settlements.viewAny'),
                'devices' => $user->can('transport-devices.viewAny'),
                'reports' => $user->can('transport-reports.view'),
            ],
        ]);
    }
}
