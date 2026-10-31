<?php

declare(strict_types=1);

namespace Modules\Transport\Application\Queries;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Transport\Application\Services\TransportSettings;
use Modules\Transport\Contracts\Data\TransportOperationsSnapshot;
use Modules\Transport\Contracts\TransportOperationalMetrics;

/**
 * The scanner fleet and the boarding backlog: devices that fell silent past
 * the configured timeout, offline boardings waiting to sync and how old the
 * oldest is, trips left open past their day, and the last quarter hour of
 * accepted and refused scans.
 */
final class TransportOperationalMetricsQuery implements TransportOperationalMetrics
{
    public function __construct(private readonly TransportSettings $settings) {}

    public function snapshot(CarbonImmutable $now): ?TransportOperationsSnapshot
    {
        if (! Schema::hasTable('transport_devices')) {
            return null;
        }
        $timeout = $this->settings->deviceInactivityTimeoutMinutes();
        $active = DB::table('transport_devices')->where('status', 'active')->whereNull('deleted_at');
        $silent = (clone $active)->where(fn ($q) => $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $now->subMinutes($timeout)->toDateTimeString()))->count();
        $pending = DB::table('transport_transactions')->where('sync_state', 'offline_pending');
        $oldest = (clone $pending)->min('scanned_at');
        $since = $now->subMinutes(15)->toDateTimeString();

        return new TransportOperationsSnapshot(
            activeDevices: (clone $active)->count(),
            silentDevices: $silent,
            offlineBoardingsPending: (clone $pending)->count(),
            oldestOfflineBoardingMinutes: $oldest ? max(0, (int) round(($now->getTimestamp() - CarbonImmutable::parse((string) $oldest)->getTimestamp()) / 60)) : 0,
            staleOpenTrips: DB::table('transport_trips')->whereDate('trip_date', '<', $now->toDateString())->whereIn('status', ['boarding', 'in_progress'])->count(),
            boardingsLast15Minutes: DB::table('transport_transactions')->where('status', 'accepted')->where('created_at', '>=', $since)->count(),
            refusalsLast15Minutes: DB::table('transport_transactions')->where('status', 'rejected')->where('created_at', '>=', $since)->count(),
        );
    }
}
