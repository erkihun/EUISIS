<?php

declare(strict_types=1);

namespace App\Services\Transport;

use App\Models\Provider;
use App\Models\TransportTransaction;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Boarding figures for the admin report (scoped to the viewer's
 * organizations) and the provider portal (scoped to the provider). Every
 * figure is a grouped count over transport_transactions; nothing is loaded
 * row by row.
 */
class TransportReportService
{
    /** Default window when no dates are given. */
    public const DEFAULT_DAYS = 30;

    public function __construct(private readonly OrganizationScopeService $scope) {}

    /**
     * @param  array{date_from?: string|null, date_to?: string|null, provider_id?: string|null, status?: string|null}  $filters
     * @return array{date_from: string, date_to: string, provider_id: string|null, status: string|null}
     */
    public function normaliseFilters(array $filters): array
    {
        $to = isset($filters['date_to']) && $filters['date_to'] !== '' ? Carbon::parse($filters['date_to']) : Carbon::today();
        $from = isset($filters['date_from']) && $filters['date_from'] !== '' ? Carbon::parse($filters['date_from']) : $to->copy()->subDays(self::DEFAULT_DAYS - 1);

        return [
            'date_from' => $from->toDateString(),
            'date_to' => $to->toDateString(),
            'provider_id' => ($filters['provider_id'] ?? null) ?: null,
            'status' => ($filters['status'] ?? null) ?: null,
        ];
    }

    /**
     * Transactions a staff user may see: employees within their organization scope.
     *
     * @param  array{date_from: string, date_to: string, provider_id: string|null, status: string|null}  $filters
     * @return Builder<TransportTransaction>
     */
    public function forAdmin(User $user, array $filters): Builder
    {
        $inScope = $this->scope->employeeIdsInScope($user);

        return $this->filtered(TransportTransaction::query(), $filters)
            ->when($inScope !== null, fn (Builder $query) => $query->whereIn('employee_id', $inScope))
            ->when($filters['provider_id'], fn (Builder $query, string $providerId) => $query->where('provider_id', $providerId));
    }

    /**
     * @param  array{date_from: string, date_to: string, provider_id: string|null, status: string|null}  $filters
     * @return Builder<TransportTransaction>
     */
    public function forProvider(Provider $provider, array $filters): Builder
    {
        return $this->filtered(TransportTransaction::query()->where('provider_id', $provider->id), $filters);
    }

    /**
     * @param  Builder<TransportTransaction>  $query
     * @return array<string, mixed>
     */
    public function report(Builder $query, bool $byProvider): array
    {
        $statuses = (clone $query)->toBase()->select('status', DB::raw('COUNT(*) AS aggregate'))->groupBy('status')->pluck('aggregate', 'status');
        $accepted = (int) ($statuses['accepted'] ?? 0);
        $rejected = (int) ($statuses['rejected'] ?? 0);

        return [
            'summary' => [
                'total' => $accepted + $rejected,
                'accepted' => $accepted,
                'rejected' => $rejected,
                'riders' => (int) (clone $query)->where('status', 'accepted')->toBase()->distinct()->count('employee_id'),
                'pay_as_you_go' => (int) (clone $query)->where('result_code', 'pay_as_you_go')->count(),
            ],
            'byRoute' => $this->grouped($query, 'transport_route_id', 'transport_routes', ['route_code', 'name_en', 'name_am']),
            'byProvider' => $byProvider ? $this->grouped($query, 'provider_id', 'providers', ['provider_code', 'name_en', 'name_am']) : [],
            'rejections' => (clone $query)->where('status', 'rejected')->toBase()
                ->select('result_code', DB::raw('COUNT(*) AS aggregate'))
                ->groupBy('result_code')->orderByDesc('aggregate')->limit(10)
                ->get()->map(fn ($row): array => ['code' => (string) $row->result_code, 'count' => (int) $row->aggregate])->all(),
            'daily' => (clone $query)->toBase()
                ->select('transaction_date', DB::raw("SUM(CASE WHEN status = 'accepted' THEN 1 ELSE 0 END) AS accepted"), DB::raw("SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) AS rejected"))
                ->groupBy('transaction_date')->orderBy('transaction_date')
                ->get()->map(fn ($row): array => [
                    'date' => Carbon::parse($row->transaction_date)->toDateString(),
                    'accepted' => (int) $row->accepted,
                    'rejected' => (int) $row->rejected,
                ])->all(),
        ];
    }

    /** Kept for the provider dashboard. @return array{total: int, accepted: int, rejected: int} */
    public function transactionSummary(Provider $provider, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $summary = $this->report($this->forProvider($provider, $this->normaliseFilters(['date_from' => $dateFrom, 'date_to' => $dateTo])), false)['summary'];

        return ['total' => $summary['total'], 'accepted' => $summary['accepted'], 'rejected' => $summary['rejected']];
    }

    /**
     * @param  Builder<TransportTransaction>  $query
     * @param  list<string>  $labelColumns
     * @return list<array<string, mixed>>
     */
    private function grouped(Builder $query, string $column, string $table, array $labelColumns): array
    {
        $rows = (clone $query)->toBase()
            ->select($column.' AS bucket', DB::raw("SUM(CASE WHEN status = 'accepted' THEN 1 ELSE 0 END) AS accepted"), DB::raw("SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) AS rejected"))
            ->whereNotNull($column)
            ->groupBy($column)
            ->orderByDesc('accepted')
            ->limit(20)
            ->get();

        $labels = DB::table($table)->whereIn('id', $rows->pluck('bucket')->all())->get(['id', ...$labelColumns])->keyBy('id');

        return $rows->map(function ($row) use ($labels, $labelColumns): array {
            $label = $labels->get($row->bucket);

            return ['id' => (string) $row->bucket, 'accepted' => (int) $row->accepted, 'rejected' => (int) $row->rejected]
                + collect($labelColumns)->mapWithKeys(fn (string $c): array => [$c => $label?->{$c}])->all();
        })->values()->all();
    }

    /**
     * @param  Builder<TransportTransaction>  $query
     * @param  array{date_from: string, date_to: string, provider_id: string|null, status: string|null}  $filters
     * @return Builder<TransportTransaction>
     */
    private function filtered(Builder $query, array $filters): Builder
    {
        return $query
            ->whereDate('transaction_date', '>=', $filters['date_from'])
            ->whereDate('transaction_date', '<=', $filters['date_to'])
            ->when($filters['status'], fn (Builder $q, string $status) => $q->where('status', $status));
    }
}
