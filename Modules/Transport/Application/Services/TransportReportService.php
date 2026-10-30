<?php

declare(strict_types=1);

namespace Modules\Transport\Application\Services;

use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Providers\Domain\Models\Provider;
use Modules\Transport\Domain\Enums\TransportTripStatus;
use Modules\Transport\Domain\Models\TransportTransaction;
use Modules\Transport\Domain\Models\TransportTrip;

/**
 * Transport figures for the admin reports (scoped to the viewer's
 * organizations), the provider portal (scoped to the provider) and the
 * Situation Room. Every figure is a grouped count over the evidence
 * tables; nothing is loaded row by row, and the four units are never mixed:
 * boardings, unique employees, employee-days and trips.
 */
class TransportReportService
{
    /** Default window when no dates are given. */
    public const DEFAULT_DAYS = 30;

    public function __construct(private readonly OrganizationScopeService $scope) {}

    /**
     * @param  array<string, string|null>  $filters
     * @return array{date_from: string, date_to: string, provider_id: string|null, status: string|null, route_id: string|null, vehicle_id: string|null, direction: string|null, verification_method: string|null}
     */
    public function normaliseFilters(array $filters): array
    {
        $to = isset($filters['date_to']) && $filters['date_to'] !== '' ? Carbon::parse($filters['date_to']) : Carbon::today();
        $from = isset($filters['date_from']) && $filters['date_from'] !== '' ? Carbon::parse($filters['date_from']) : $to->copy()->subDays(self::DEFAULT_DAYS - 1);
        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        return [
            'date_from' => $from->toDateString(),
            'date_to' => $to->toDateString(),
            'provider_id' => ($filters['provider_id'] ?? null) ?: null,
            'status' => ($filters['status'] ?? null) ?: null,
            'route_id' => ($filters['route_id'] ?? null) ?: null,
            'vehicle_id' => ($filters['vehicle_id'] ?? null) ?: null,
            'direction' => in_array($filters['direction'] ?? null, ['inbound', 'outbound'], true) ? $filters['direction'] : null,
            'verification_method' => ($filters['verification_method'] ?? null) ?: null,
        ];
    }

    /**
     * Transactions a staff user may see: employees within their organization scope.
     *
     * @param  array<string, string|null>  $filters
     * @return Builder<TransportTransaction>
     */
    public function forAdmin(User $user, array $filters): Builder
    {
        $inScope = $this->scope->employeeIdsInScope($user);

        return $this->filtered(TransportTransaction::query(), $filters)
            ->when($inScope !== null, fn (Builder $query) => $query->whereIn('transport_transactions.employee_id', $inScope))
            ->when($filters['provider_id'], fn (Builder $query, string $providerId) => $query->where('transport_transactions.provider_id', $providerId));
    }

    /**
     * @param  array<string, string|null>  $filters
     * @return Builder<TransportTransaction>
     */
    public function forProvider(Provider $provider, array $filters): Builder
    {
        return $this->filtered(TransportTransaction::query()->where('transport_transactions.provider_id', $provider->id), $filters);
    }

    /**
     * The headline report: totals, the four units, the two directions, and
     * breakdowns by route, provider, vehicle, refusal reason and day.
     *
     * @param  Builder<TransportTransaction>  $query
     * @return array<string, mixed>
     */
    public function report(Builder $query, bool $byProvider): array
    {
        $statuses = (clone $query)->toBase()->select('status', DB::raw('COUNT(*) AS aggregate'))->groupBy('status')->pluck('aggregate', 'status');
        $accepted = (int) ($statuses['accepted'] ?? 0);
        $rejected = (int) ($statuses['rejected'] ?? 0);
        $verified = (clone $query)->verified();
        $directions = (clone $verified)->toBase()->select('direction', DB::raw('COUNT(*) AS aggregate'))->groupBy('direction')->pluck('aggregate', 'direction');
        $methods = (clone $verified)->toBase()->select('verification_method', DB::raw('COUNT(*) AS aggregate'))->groupBy('verification_method')->pluck('aggregate', 'verification_method');

        return [
            'summary' => [
                'total' => $accepted + $rejected,
                'accepted' => $accepted,
                'rejected' => $rejected,
                'verified' => (int) (clone $verified)->count(),
                'voided' => (int) (clone $query)->where('status', 'accepted')->whereNotNull('voided_at')->count(),
                'riders' => (int) (clone $verified)->toBase()->distinct()->count('employee_id'),
                'employee_days' => $this->employeeDays((clone $verified)->toBase()),
                'inbound' => (int) ($directions['inbound'] ?? 0),
                'outbound' => (int) ($directions['outbound'] ?? 0),
                'manual' => (int) ($methods['manual_exception'] ?? 0),
                'nfc' => (int) ($methods['nfc'] ?? 0),
                'pay_as_you_go' => (int) (clone $query)->where('result_code', 'pay_as_you_go')->count(),
                'trips' => (int) (clone $verified)->toBase()->distinct()->count('transport_trip_id'),
            ],
            'byRoute' => $this->grouped($query, 'transport_route_id', 'transport_routes', ['route_code', 'name_en', 'name_am']),
            'byProvider' => $byProvider ? $this->grouped($query, 'provider_id', 'providers', ['provider_code', 'name_en', 'name_am']) : [],
            'byVehicle' => $this->grouped($query, 'transport_vehicle_id', 'transport_vehicles', ['vehicle_code', 'plate_number']),
            'rejections' => (clone $query)->where('status', 'rejected')->toBase()
                ->select('result_code', DB::raw('COUNT(*) AS aggregate'))
                ->groupBy('result_code')->orderByDesc('aggregate')->limit(10)
                ->get()->map(fn ($row): array => ['code' => (string) $row->result_code, 'count' => (int) $row->aggregate])->all(),
            'daily' => (clone $query)->toBase()
                ->select('transaction_date',
                    DB::raw("SUM(CASE WHEN status = 'accepted' AND voided_at IS NULL THEN 1 ELSE 0 END) AS accepted"),
                    DB::raw("SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) AS rejected"),
                    DB::raw("SUM(CASE WHEN status = 'accepted' AND voided_at IS NULL AND direction = 'inbound' THEN 1 ELSE 0 END) AS inbound"),
                    DB::raw("SUM(CASE WHEN status = 'accepted' AND voided_at IS NULL AND direction = 'outbound' THEN 1 ELSE 0 END) AS outbound"),
                    DB::raw("COUNT(DISTINCT CASE WHEN status = 'accepted' AND voided_at IS NULL THEN employee_id END) AS riders"))
                ->groupBy('transaction_date')->orderBy('transaction_date')
                ->get()->map(fn ($row): array => [
                    'date' => Carbon::parse($row->transaction_date)->toDateString(),
                    'accepted' => (int) $row->accepted,
                    'rejected' => (int) $row->rejected,
                    'inbound' => (int) $row->inbound,
                    'outbound' => (int) $row->outbound,
                    'riders' => (int) $row->riders,
                ])->all(),
        ];
    }

    /**
     * Route utilization: assigned employees (valid route assignments in the
     * period), actual unique riders, boardings, trips, seats offered and the
     * no-show rate where the denominator is valid.
     *
     * @param  array<string, string|null>  $filters
     * @return list<array<string, mixed>>
     */
    public function routeUtilization(array $filters, ?Provider $provider = null, ?User $viewer = null): array
    {
        $inScope = $viewer !== null ? $this->scope->employeeIdsInScope($viewer) : null;
        $trips = $this->tripQuery($filters, $provider);

        $tripStats = (clone $trips)->leftJoin('transport_vehicles', 'transport_vehicles.id', '=', 'transport_trips.transport_vehicle_id')
            ->select('transport_trips.transport_route_id',
                DB::raw('COUNT(*) AS scheduled'),
                DB::raw("SUM(CASE WHEN transport_trips.status = 'completed' THEN 1 ELSE 0 END) AS completed"),
                DB::raw("SUM(CASE WHEN transport_trips.status IN ('cancelled','failed') THEN 1 ELSE 0 END) AS cancelled"),
                DB::raw("SUM(CASE WHEN transport_trips.status = 'completed' THEN COALESCE(transport_trips.capacity, transport_vehicles.capacity + COALESCE(transport_vehicles.standing_capacity, 0), 0) ELSE 0 END) AS seats"))
            ->groupBy('transport_trips.transport_route_id')->get()->keyBy('transport_route_id');

        $boardings = $this->verifiedQuery($filters, $provider, $inScope)
            ->select('transport_transactions.transport_route_id', DB::raw('COUNT(*) AS boardings'), DB::raw('COUNT(DISTINCT transport_transactions.employee_id) AS riders'),
                DB::raw("SUM(CASE WHEN transport_transactions.direction = 'inbound' THEN 1 ELSE 0 END) AS inbound"),
                DB::raw("SUM(CASE WHEN transport_transactions.direction = 'outbound' THEN 1 ELSE 0 END) AS outbound"))
            ->groupBy('transport_transactions.transport_route_id')->get()->keyBy('transport_route_id');

        $assigned = DB::table('transport_passes')->select('transport_route_id', DB::raw('COUNT(DISTINCT employee_id) AS assigned'))
            ->whereNotNull('transport_route_id')->where('status', 'active')
            ->whereDate('valid_from', '<=', $filters['date_to'])->where(fn ($q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', $filters['date_from']))
            ->when($inScope !== null, fn ($q) => $q->whereIn('employee_id', $inScope))
            ->groupBy('transport_route_id')->pluck('assigned', 'transport_route_id');

        $routeIds = collect($tripStats->keys())->merge($boardings->keys())->merge($assigned->keys())->filter()->unique()->values();
        $routes = DB::table('transport_routes')->whereIn('id', $routeIds->all())->when($provider !== null, fn ($q) => $q->where('provider_id', $provider->id))
            ->when($filters['route_id'], fn ($q, $id) => $q->where('id', $id))->get(['id', 'route_code', 'name_en', 'name_am', 'provider_id'])->keyBy('id');

        return $routes->map(function ($route) use ($tripStats, $boardings, $assigned): array {
            $t = $tripStats->get($route->id);
            $b = $boardings->get($route->id);
            $seats = (int) ($t->seats ?? 0);
            $riders = (int) ($b->riders ?? 0);
            $assignedCount = (int) ($assigned->get($route->id) ?? 0);

            return [
                'route_id' => (string) $route->id,
                'route_code' => $route->route_code,
                'name_en' => $route->name_en,
                'name_am' => $route->name_am,
                'assigned_employees' => $assignedCount,
                'unique_riders' => $riders,
                'boardings' => (int) ($b->boardings ?? 0),
                'inbound' => (int) ($b->inbound ?? 0),
                'outbound' => (int) ($b->outbound ?? 0),
                'scheduled_trips' => (int) ($t->scheduled ?? 0),
                'completed_trips' => (int) ($t->completed ?? 0),
                'cancelled_trips' => (int) ($t->cancelled ?? 0),
                'seats_offered' => $seats,
                'utilization_percent' => $seats > 0 ? (int) round(($b->boardings ?? 0) * 100 / $seats) : null,
                'no_show_rate_percent' => $assignedCount > 0 ? max(0, (int) round(($assignedCount - $riders) * 100 / $assignedCount)) : null,
            ];
        })->sortByDesc('boardings')->values()->all();
    }

    /**
     * @param  array<string, string|null>  $filters
     * @return list<array<string, mixed>>
     */
    public function vehicleUtilization(array $filters, ?Provider $provider = null): array
    {
        $rows = $this->tripQuery($filters, $provider)
            ->join('transport_vehicles', 'transport_vehicles.id', '=', 'transport_trips.transport_vehicle_id')
            ->select('transport_vehicles.id', 'transport_vehicles.vehicle_code', 'transport_vehicles.plate_number', 'transport_vehicles.capacity', 'transport_vehicles.standing_capacity',
                DB::raw('COUNT(*) AS trips'),
                DB::raw("SUM(CASE WHEN transport_trips.status = 'completed' THEN 1 ELSE 0 END) AS completed"),
                DB::raw("SUM(CASE WHEN transport_trips.status = 'completed' THEN transport_trips.boarded_count ELSE 0 END) AS passengers"),
                DB::raw("SUM(CASE WHEN transport_trips.status = 'completed' THEN COALESCE(transport_trips.capacity, transport_vehicles.capacity + COALESCE(transport_vehicles.standing_capacity, 0), 0) ELSE 0 END) AS seats"),
                DB::raw('COUNT(DISTINCT transport_trips.trip_date) AS days_operated'),
                DB::raw('COALESCE(SUM(transport_trips.distance_km), 0) AS distance_km'))
            ->groupBy('transport_vehicles.id', 'transport_vehicles.vehicle_code', 'transport_vehicles.plate_number', 'transport_vehicles.capacity', 'transport_vehicles.standing_capacity')
            ->orderByDesc('passengers')->limit(200)->get();

        return $rows->map(fn ($row): array => [
            'vehicle_id' => (string) $row->id,
            'vehicle_code' => $row->vehicle_code,
            'plate_number' => $row->plate_number,
            'capacity' => $row->capacity !== null ? (int) $row->capacity + (int) ($row->standing_capacity ?? 0) : null,
            'trips' => (int) $row->trips,
            'completed_trips' => (int) $row->completed,
            'passengers' => (int) $row->passengers,
            'seats_offered' => (int) $row->seats,
            'utilization_percent' => (int) $row->seats > 0 ? (int) round($row->passengers * 100 / $row->seats) : null,
            'days_operated' => (int) $row->days_operated,
            'distance_km' => (string) $row->distance_km,
        ])->all();
    }

    /**
     * Factual provider delivery: scheduled, completed and cancelled trips,
     * verified boardings, utilization and exceptions. No ranking.
     *
     * @param  array<string, string|null>  $filters
     * @return list<array<string, mixed>>
     */
    public function providerPerformance(array $filters, ?User $viewer = null): array
    {
        $inScope = $viewer !== null ? $this->scope->employeeIdsInScope($viewer) : null;
        $trips = $this->tripQuery($filters, null)->leftJoin('transport_vehicles', 'transport_vehicles.id', '=', 'transport_trips.transport_vehicle_id')
            ->select('transport_trips.provider_id', DB::raw('COUNT(*) AS scheduled'),
                DB::raw("SUM(CASE WHEN transport_trips.status = 'completed' THEN 1 ELSE 0 END) AS completed"),
                DB::raw("SUM(CASE WHEN transport_trips.status IN ('cancelled','failed') THEN 1 ELSE 0 END) AS cancelled"),
                DB::raw("SUM(CASE WHEN transport_trips.status = 'completed' THEN COALESCE(transport_trips.capacity, transport_vehicles.capacity + COALESCE(transport_vehicles.standing_capacity, 0), 0) ELSE 0 END) AS seats"))
            ->groupBy('transport_trips.provider_id')->get()->keyBy('provider_id');
        $boardings = $this->verifiedQuery($filters, null, $inScope)->select('transport_transactions.provider_id', DB::raw('COUNT(*) AS boardings'), DB::raw('COUNT(DISTINCT transport_transactions.employee_id) AS riders'))
            ->groupBy('transport_transactions.provider_id')->get()->keyBy('provider_id');
        $exceptions = DB::table('transport_exceptions')->select('provider_id', DB::raw('COUNT(*) AS n'))
            ->whereBetween('occurred_at', [$filters['date_from'].' 00:00:00', $filters['date_to'].' 23:59:59'])->groupBy('provider_id')->pluck('n', 'provider_id');
        $providers = DB::table('providers')->whereIn('id', $trips->keys()->merge($boardings->keys())->unique()->all())->get(['id', 'provider_code', 'name_en', 'name_am']);

        return $providers->map(function ($p) use ($trips, $boardings, $exceptions): array {
            $t = $trips->get($p->id);
            $b = $boardings->get($p->id);
            $seats = (int) ($t->seats ?? 0);

            return [
                'provider_id' => (string) $p->id, 'provider_code' => $p->provider_code, 'name_en' => $p->name_en, 'name_am' => $p->name_am,
                'scheduled_trips' => (int) ($t->scheduled ?? 0), 'completed_trips' => (int) ($t->completed ?? 0), 'cancelled_trips' => (int) ($t->cancelled ?? 0),
                'completion_rate_percent' => ($t->scheduled ?? 0) > 0 ? (int) round($t->completed * 100 / $t->scheduled) : null,
                'verified_boardings' => (int) ($b->boardings ?? 0), 'unique_riders' => (int) ($b->riders ?? 0),
                'utilization_percent' => $seats > 0 ? (int) round(($b->boardings ?? 0) * 100 / $seats) : null,
                'exceptions' => (int) ($exceptions->get($p->id) ?? 0),
            ];
        })->sortBy('provider_code')->values()->all();
    }

    /**
     * Daily usage: assigned employees, unique riders, morning and evening
     * riders, boardings, completed trips and vehicles used, per day.
     *
     * @param  array<string, string|null>  $filters
     * @return list<array<string, mixed>>
     */
    public function dailyUsage(array $filters, ?Provider $provider = null, ?User $viewer = null): array
    {
        $inScope = $viewer !== null ? $this->scope->employeeIdsInScope($viewer) : null;
        $boardings = $this->verifiedQuery($filters, $provider, $inScope)
            ->select('transport_transactions.transaction_date', DB::raw('COUNT(*) AS boardings'), DB::raw('COUNT(DISTINCT transport_transactions.employee_id) AS riders'),
                DB::raw("COUNT(DISTINCT CASE WHEN transport_transactions.direction = 'inbound' THEN transport_transactions.employee_id END) AS morning_riders"),
                DB::raw("COUNT(DISTINCT CASE WHEN transport_transactions.direction = 'outbound' THEN transport_transactions.employee_id END) AS evening_riders"))
            ->groupBy('transport_transactions.transaction_date')->get()->keyBy(fn ($r) => Carbon::parse($r->transaction_date)->toDateString());
        $trips = $this->tripQuery($filters, $provider)
            ->select('transport_trips.trip_date', DB::raw("SUM(CASE WHEN transport_trips.status = 'completed' THEN 1 ELSE 0 END) AS completed"),
                DB::raw("COUNT(DISTINCT CASE WHEN transport_trips.status = 'completed' THEN transport_trips.transport_vehicle_id END) AS vehicles"))
            ->groupBy('transport_trips.trip_date')->get()->keyBy(fn ($r) => Carbon::parse($r->trip_date)->toDateString());
        $assigned = (int) DB::table('transport_passes')->where('status', 'active')->when($provider !== null, fn ($q) => $q->where('provider_id', $provider->id))
            ->whereDate('valid_from', '<=', $filters['date_to'])->where(fn ($q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', $filters['date_from']))
            ->when($inScope !== null, fn ($q) => $q->whereIn('employee_id', $inScope))->distinct()->count('employee_id');

        $rows = [];
        $cursor = Carbon::parse($filters['date_from']);
        $end = Carbon::parse($filters['date_to']);
        while ($cursor->lte($end)) {
            $day = $cursor->toDateString();
            $b = $boardings->get($day);
            $t = $trips->get($day);
            if ($b !== null || $t !== null) {
                $rows[] = [
                    'date' => $day,
                    'assigned_employees' => $assigned,
                    'unique_riders' => (int) ($b->riders ?? 0),
                    'morning_riders' => (int) ($b->morning_riders ?? 0),
                    'evening_riders' => (int) ($b->evening_riders ?? 0),
                    'boardings' => (int) ($b->boardings ?? 0),
                    'completed_trips' => (int) ($t->completed ?? 0),
                    'vehicles_used' => (int) ($t->vehicles ?? 0),
                ];
            }
            $cursor->addDay();
        }

        return $rows;
    }

    /**
     * Who boarded a trip. The provider tier shows name and number; the staff
     * tier adds the organization. Nothing beyond that leaves this method.
     *
     * @return array{trip: TransportTrip, rows: list<array<string, mixed>>, totals: array<string, int>}
     */
    public function manifest(TransportTrip $trip, bool $staffTier): array
    {
        $trip->loadMissing(['route', 'vehicle', 'driver', 'provider', 'vehicleChanges.fromVehicle', 'vehicleChanges.toVehicle']);
        $rows = TransportTransaction::query()
            ->with(['employee.currentAssignment.organization', 'stop', 'device', 'vehicle'])
            ->where('transport_trip_id', $trip->id)
            ->orderBy('scanned_at')
            ->get()
            ->map(fn (TransportTransaction $t): array => [
                'id' => $t->id,
                'employee_number' => $t->employee?->employee_number,
                'full_name' => $t->employee?->full_name,
                'organization' => $staffTier ? $t->employee?->currentAssignment?->organization?->name_en : null,
                'organization_am' => $staffTier ? $t->employee?->currentAssignment?->organization?->name_am : null,
                'boarded_at' => $t->scanned_at?->toIso8601String(),
                'stop' => $t->stop ? ['stop_code' => $t->stop->stop_code, 'name_en' => $t->stop->name_en, 'name_am' => $t->stop->name_am] : null,
                'verification_method' => $t->verification_method?->value,
                'status' => $t->voided_at !== null ? 'voided' : $t->status,
                'result_code' => $t->result_code,
                'sync_state' => $t->sync_state?->value,
                'vehicle_plate' => $t->vehicle?->plate_number,
                'device_code' => $t->device?->device_code,
                'manual_reason' => $staffTier ? $t->manual_reason : null,
            ])->values()->all();

        return [
            'trip' => $trip,
            'rows' => $rows,
            'totals' => [
                'accepted' => count(array_filter($rows, fn (array $r): bool => $r['status'] === 'accepted')),
                'rejected' => count(array_filter($rows, fn (array $r): bool => $r['status'] === 'rejected')),
                'voided' => count(array_filter($rows, fn (array $r): bool => $r['status'] === 'voided')),
                'manual' => count(array_filter($rows, fn (array $r): bool => $r['verification_method'] === 'manual_exception' && $r['status'] === 'accepted')),
                'expected_riders' => (int) ($trip->expected_riders ?? 0),
            ],
        ];
    }

    /** Kept for the provider dashboard. @return array{total: int, accepted: int, rejected: int} */
    public function transactionSummary(Provider $provider, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $summary = $this->report($this->forProvider($provider, $this->normaliseFilters(['date_from' => $dateFrom, 'date_to' => $dateTo])), false)['summary'];

        return ['total' => $summary['total'], 'accepted' => $summary['accepted'], 'rejected' => $summary['rejected']];
    }

    /** Verified boardings in the period as a query builder on transport_transactions (joined to trips). */
    /** The upper bound carries a time: a date column stored with one (SQLite) would otherwise drop the last day of the window. */
    private function verifiedQuery(array $filters, ?Provider $provider, ?Builder $inScope): QueryBuilder
    {
        return DB::table('transport_transactions')
            ->join('transport_trips', 'transport_trips.id', '=', 'transport_transactions.transport_trip_id')
            ->where('transport_transactions.status', 'accepted')->whereNull('transport_transactions.voided_at')
            ->whereNotIn('transport_trips.status', [TransportTripStatus::Cancelled->value, TransportTripStatus::Failed->value])
            ->whereBetween('transport_transactions.transaction_date', [$filters['date_from'], $filters['date_to'].' 23:59:59'])
            ->when($provider !== null, fn ($q) => $q->where('transport_transactions.provider_id', $provider->id))
            ->when($filters['provider_id'] ?? null, fn ($q, $id) => $q->where('transport_transactions.provider_id', $id))
            ->when($filters['route_id'] ?? null, fn ($q, $id) => $q->where('transport_transactions.transport_route_id', $id))
            ->when($filters['vehicle_id'] ?? null, fn ($q, $id) => $q->where('transport_transactions.transport_vehicle_id', $id))
            ->when($filters['direction'] ?? null, fn ($q, $d) => $q->where('transport_transactions.direction', $d))
            ->when($inScope !== null, fn ($q) => $q->whereIn('transport_transactions.employee_id', $inScope));
    }

    private function tripQuery(array $filters, ?Provider $provider): QueryBuilder
    {
        return DB::table('transport_trips')
            ->whereBetween('transport_trips.trip_date', [$filters['date_from'], $filters['date_to'].' 23:59:59'])
            ->when($provider !== null, fn ($q) => $q->where('transport_trips.provider_id', $provider->id))
            ->when($filters['provider_id'] ?? null, fn ($q, $id) => $q->where('transport_trips.provider_id', $id))
            ->when($filters['route_id'] ?? null, fn ($q, $id) => $q->where('transport_trips.transport_route_id', $id))
            ->when($filters['vehicle_id'] ?? null, fn ($q, $id) => $q->where('transport_trips.transport_vehicle_id', $id))
            ->when($filters['direction'] ?? null, fn ($q, $d) => $q->where('transport_trips.direction', $d));
    }

    private function employeeDays(QueryBuilder $verified): int
    {
        return (int) DB::query()->fromSub($verified->select('employee_id', 'transaction_date')->distinct(), 'd')->count();
    }

    /**
     * @param  Builder<TransportTransaction>  $query
     * @param  list<string>  $labelColumns
     * @return list<array<string, mixed>>
     */
    private function grouped(Builder $query, string $column, string $table, array $labelColumns): array
    {
        $rows = (clone $query)->toBase()
            ->select($column.' AS bucket',
                DB::raw("SUM(CASE WHEN status = 'accepted' AND voided_at IS NULL THEN 1 ELSE 0 END) AS accepted"),
                DB::raw("SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) AS rejected"),
                DB::raw("COUNT(DISTINCT CASE WHEN status = 'accepted' AND voided_at IS NULL THEN employee_id END) AS riders"),
                DB::raw("SUM(CASE WHEN status = 'accepted' AND voided_at IS NULL AND direction = 'inbound' THEN 1 ELSE 0 END) AS inbound"),
                DB::raw("SUM(CASE WHEN status = 'accepted' AND voided_at IS NULL AND direction = 'outbound' THEN 1 ELSE 0 END) AS outbound"))
            ->whereNotNull($column)
            ->groupBy($column)
            ->orderByDesc('accepted')
            ->limit(20)
            ->get();

        $labels = DB::table($table)->whereIn('id', $rows->pluck('bucket')->all())->get(['id', ...$labelColumns])->keyBy('id');

        return $rows->map(function ($row) use ($labels, $labelColumns): array {
            $label = $labels->get($row->bucket);

            return ['id' => (string) $row->bucket, 'accepted' => (int) $row->accepted, 'rejected' => (int) $row->rejected, 'riders' => (int) $row->riders, 'inbound' => (int) $row->inbound, 'outbound' => (int) $row->outbound]
                + collect($labelColumns)->mapWithKeys(fn (string $c): array => [$c => $label?->{$c}])->all();
        })->values()->all();
    }

    /**
     * @param  Builder<TransportTransaction>  $query
     * @param  array<string, string|null>  $filters
     * @return Builder<TransportTransaction>
     */
    private function filtered(Builder $query, array $filters): Builder
    {
        return $query
            ->whereDate('transport_transactions.transaction_date', '>=', $filters['date_from'])
            ->whereDate('transport_transactions.transaction_date', '<=', $filters['date_to'])
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('transport_transactions.status', $status))
            ->when($filters['route_id'] ?? null, fn (Builder $q, string $id) => $q->where('transport_transactions.transport_route_id', $id))
            ->when($filters['vehicle_id'] ?? null, fn (Builder $q, string $id) => $q->where('transport_transactions.transport_vehicle_id', $id))
            ->when($filters['direction'] ?? null, fn (Builder $q, string $d) => $q->where('transport_transactions.direction', $d))
            ->when($filters['verification_method'] ?? null, fn (Builder $q, string $m) => $q->where('transport_transactions.verification_method', $m));
    }
}
