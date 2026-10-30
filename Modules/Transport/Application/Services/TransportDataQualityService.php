<?php

declare(strict_types=1);

namespace Modules\Transport\Application\Services;

use Illuminate\Support\Facades\DB;
use Modules\Transport\Domain\Enums\TransportSyncState;
use Modules\Transport\Domain\Enums\TransportTripStatus;
use Modules\Transport\Domain\Models\TransportContract;

/**
 * Configuration and data-quality checks the transport desk and the
 * Intelligence data-quality area read: counts only, each with the page
 * that fixes it. Nothing here changes data.
 */
class TransportDataQualityService
{
    /** @return list<array{code: string, count: int, severity: string}> */
    public function summary(): array
    {
        $today = today()->toDateString();
        $checks = [
            ['eligible_employee_without_route', 'warning', fn (): int => (int) DB::table('transport_eligibilities')->where('subject_type', 'employee')->where('status', 'active')
                ->whereNotIn('employee_id', DB::table('transport_passes')->select('employee_id')->where('status', 'active'))->count()],
            ['route_without_schedule', 'warning', fn (): int => (int) DB::table('transport_routes')->where('is_active', true)->whereNull('deleted_at')
                ->whereNotIn('id', DB::table('transport_schedules')->select('transport_route_id')->where('is_active', true)->whereNull('deleted_at'))->count()],
            ['route_without_stops', 'info', fn (): int => (int) DB::table('transport_routes')->where('is_active', true)->whereNull('deleted_at')
                ->whereNotIn('id', DB::table('transport_route_stops')->select('transport_route_id')->where('is_active', true)->whereNull('deleted_at'))->count()],
            ['trip_without_vehicle', 'warning', fn (): int => (int) DB::table('transport_trips')->whereDate('trip_date', '>=', $today)->whereNull('transport_vehicle_id')
                ->whereIn('status', [TransportTripStatus::Planned->value, TransportTripStatus::Assigned->value])->count()],
            ['vehicle_without_capacity', 'critical', fn (): int => (int) DB::table('transport_vehicles')->where('status', 'active')->whereNull('deleted_at')->whereNull('capacity')->count()],
            ['stale_open_trips', 'warning', fn (): int => (int) DB::table('transport_trips')->whereDate('trip_date', '<', $today)->whereIn('status', TransportTripStatus::boardable())->count()],
            ['boardings_on_uncompleted_trips', 'warning', fn (): int => (int) DB::table('transport_transactions')->join('transport_trips', 'transport_trips.id', '=', 'transport_transactions.transport_trip_id')
                ->where('transport_transactions.status', 'accepted')->whereNull('transport_transactions.voided_at')->whereDate('transport_transactions.transaction_date', '<', $today)
                ->whereIn('transport_trips.status', [TransportTripStatus::Planned->value, TransportTripStatus::Assigned->value, TransportTripStatus::Boarding->value, TransportTripStatus::InProgress->value])->count()],
            ['offline_pending_boardings', 'warning', fn (): int => (int) DB::table('transport_transactions')->where('sync_state', TransportSyncState::OfflinePending->value)->count()],
            ['provider_without_contract', 'critical', fn (): int => (int) DB::table('providers')->join('provider_services', 'provider_services.provider_id', '=', 'providers.id')
                ->join('service_types', 'service_types.id', '=', 'provider_services.service_type_id')->where('service_types.code', 'transport')->where('provider_services.status', 'active')->where('providers.status', 'active')
                ->whereNotIn('providers.id', DB::table('transport_contracts')->select('provider_id')->where('status', 'active')->whereNull('deleted_at'))->distinct()->count('providers.id')],
            ['contract_missing_tariff', 'critical', fn (): int => $this->contractsMissingTerms()],
            ['invoice_without_statement', 'info', fn (): int => (int) DB::table('transport_invoices')->whereIn('status', ['submitted'])->whereNull('transport_settlement_id')->count()],
            ['expired_vehicle_documents', 'warning', fn (): int => (int) DB::table('transport_vehicles')->where('status', 'active')->whereNull('deleted_at')
                ->where(fn ($q) => $q->whereDate('inspection_expires_at', '<', $today)->orWhereDate('insurance_expires_at', '<', $today)->orWhereDate('permit_expires_at', '<', $today))->count()],
        ];

        $rows = [];
        foreach ($checks as [$code, $severity, $count]) {
            try {
                $rows[] = ['code' => $code, 'count' => $count(), 'severity' => $severity];
            } catch (\Throwable) {
                $rows[] = ['code' => $code, 'count' => -1, 'severity' => $severity];
            }
        }

        return $rows;
    }

    private function contractsMissingTerms(): int
    {
        return TransportContract::query()->where('status', 'active')->get()->filter(fn ($c) => $c->missingTerms() !== [])->count();
    }
}
