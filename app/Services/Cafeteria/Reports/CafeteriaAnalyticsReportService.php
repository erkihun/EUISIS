<?php

declare(strict_types=1);

namespace App\Services\Cafeteria\Reports;

use App\Enums\CafeteriaTransactionStatus;
use App\Models\CafeteriaProvider;
use App\Models\CafeteriaServiceNetwork;
use App\Models\CafeteriaServicePolicy;
use App\Models\CafeteriaSettlement;
use App\Models\CafeteriaTransaction;
use App\Models\CafeteriaTransactionConsumedDay;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\OrganizationCafeteriaAccess;
use App\Models\Provider;
use App\Services\Cafeteria\Policy\CafeteriaConfigurationHealthService;
use App\Services\Cafeteria\Policy\CafeteriaPricing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Cafeteria analytics (docs/cafeteria-settlement-rules.md → Reporting).
 *
 * Financial reports read the transactions' APPLIED amounts only. Billing is
 * grouped by the employee organization, payables by the provider that served,
 * and usage by the cafeteria where service happened — three different owners.
 */
class CafeteriaAnalyticsReportService
{
    public const TYPES = [
        'daily_transactions', 'organization_subsidy_usage', 'organization_liability', 'provider_payables',
        'cafeteria_usage', 'network_usage', 'branch_usage', 'employee_usage', 'advance_usage',
        'policy_history', 'missing_policy', 'access_matrix', 'settlement_report',
    ];

    public function __construct(private readonly CafeteriaConfigurationHealthService $health) {}

    /**
     * @param  array{date_from: string, date_to: string, organization_id?: string|null, provider_id?: string|null, network_id?: string|null, cafeteria_id?: string|null}  $filters
     * @param  list<string>|null  $organizationIds  null = unrestricted
     * @return array{columns: list<string>, rows: list<array<string, mixed>>}
     */
    public function run(string $type, array $filters, ?array $organizationIds = null): array
    {
        return match ($type) {
            'daily_transactions' => $this->grouped($filters, $organizationIds, ['transaction_date'], ['date']),
            'organization_subsidy_usage' => $this->grouped($filters, $organizationIds, ['employee_organization_id'], ['organization'], withDays: true),
            'organization_liability' => $this->grouped($filters, $organizationIds, ['employee_organization_id', 'provider_id'], ['organization', 'provider']),
            'provider_payables' => $this->grouped($filters, $organizationIds, ['provider_id'], ['provider']),
            'cafeteria_usage' => $this->grouped($filters, $organizationIds, ['cafeteria_provider_id'], ['cafeteria']),
            'network_usage' => $this->grouped($filters, $organizationIds, ['cafeteria_service_network_id'], ['network']),
            'branch_usage' => $this->grouped($filters, $organizationIds, ['cafeteria_provider_id'], ['cafeteria'], branchesOnly: true),
            'employee_usage' => $this->grouped($filters, $organizationIds, ['employee_id', 'employee_organization_id'], ['employee', 'organization'], withDays: true),
            'advance_usage' => $this->advanceUsage($filters, $organizationIds),
            'policy_history' => $this->policyHistory($filters, $organizationIds),
            'missing_policy' => $this->missingPolicy($organizationIds),
            'access_matrix' => $this->accessMatrix($filters, $organizationIds),
            'settlement_report' => $this->settlementReport($filters),
            default => ['columns' => [], 'rows' => []],
        };
    }

    /**
     * @param  list<string>  $groupBy  transaction columns
     * @param  list<string>  $labels  one label column per group column
     * @return array{columns: list<string>, rows: list<array<string, mixed>>}
     */
    private function grouped(array $filters, ?array $organizationIds, array $groupBy, array $labels, bool $withDays = false, bool $branchesOnly = false): array
    {
        $query = $this->transactions($filters, $organizationIds)
            ->when($branchesOnly, fn (Builder $q) => $q->whereIn('cafeteria_provider_id', CafeteriaProvider::query()->whereIn('location_type', ['branch', 'service_point'])->select('id')))
            ->selectRaw(implode(', ', $groupBy).', COUNT(*) as transaction_count, SUM(consumed_days_count) as entitlement_days, SUM(subsidy_amount_applied) as subsidy, SUM(employee_payable_amount) as employee_share')
            ->groupBy($groupBy);

        $rows = $query->get()->map(fn ($row): array => [
            ...array_combine($groupBy, array_map(fn ($column) => $row->{$column}, $groupBy)),
            'transaction_count' => (int) $row->transaction_count,
            'entitlement_days' => (int) $row->entitlement_days,
            'subsidy' => CafeteriaPricing::format(CafeteriaPricing::toCents((string) $row->subsidy)),
            'employee_share' => CafeteriaPricing::format(CafeteriaPricing::toCents((string) $row->employee_share)),
            'provider_total' => CafeteriaPricing::format(CafeteriaPricing::toCents((string) $row->subsidy) + CafeteriaPricing::toCents((string) $row->employee_share)),
        ]);

        $rows = $this->label($rows, $groupBy, $labels)->sortBy(fn (array $r) => implode('|', array_map(fn ($l) => (string) ($r[$l] ?? ''), $labels)))->values();

        return [
            'columns' => [...$labels, 'transactions', ...($withDays ? ['entitlement_days'] : []), 'subsidy', 'employee_share', 'provider_total'],
            'rows' => $rows->map(fn (array $r) => [
                ...array_intersect_key($r, array_flip($labels)),
                'transactions' => $r['transaction_count'],
                ...($withDays ? ['entitlement_days' => $r['entitlement_days']] : []),
                'subsidy' => $r['subsidy'],
                'employee_share' => $r['employee_share'],
                'provider_total' => $r['provider_total'],
            ])->all(),
        ];
    }

    /** @return array{columns: list<string>, rows: list<array<string, mixed>>} */
    private function advanceUsage(array $filters, ?array $organizationIds): array
    {
        $rows = CafeteriaTransactionConsumedDay::query()
            ->whereIn('usage_type', ['advance', 'extra_deduction'])
            ->whereNotNull('active_key')
            ->whereBetween('consumed_date', [$filters['date_from'], $filters['date_to'].' 23:59:59'])
            ->when($organizationIds !== null, fn (Builder $q) => $q->whereIn('organization_id', $organizationIds))
            ->when(filled($filters['organization_id'] ?? null), fn (Builder $q) => $q->where('organization_id', $filters['organization_id']))
            ->selectRaw('organization_id, consumed_at_cafeteria_id, usage_type, COUNT(*) as entitlement_days, SUM(subsidy_amount) as subsidy')
            ->groupBy(['organization_id', 'consumed_at_cafeteria_id', 'usage_type'])
            ->get()
            ->map(fn ($row): array => [
                'employee_organization_id' => $row->organization_id,
                'cafeteria_provider_id' => $row->consumed_at_cafeteria_id,
                'usage_type' => $row->usage_type,
                'entitlement_days' => (int) $row->entitlement_days,
                'subsidy' => CafeteriaPricing::format(CafeteriaPricing::toCents((string) $row->subsidy)),
            ]);

        return [
            'columns' => ['organization', 'cafeteria', 'usage_type', 'entitlement_days', 'subsidy'],
            'rows' => $this->label($rows, ['employee_organization_id', 'cafeteria_provider_id'], ['organization', 'cafeteria'])
                ->map(fn (array $r) => array_intersect_key($r, array_flip(['organization', 'cafeteria', 'usage_type', 'entitlement_days', 'subsidy'])))
                ->values()->all(),
        ];
    }

    /** @return array{columns: list<string>, rows: list<array<string, mixed>>} */
    private function policyHistory(array $filters, ?array $organizationIds): array
    {
        $rows = CafeteriaServicePolicy::query()
            ->with(['organization:id,code,name_en', 'provider:id,provider_code,name_en', 'network:id,code,name_en', 'cafeteria:id,code,name_en'])
            ->when($organizationIds !== null, fn (Builder $q) => $q->whereIn('organization_id', $organizationIds))
            ->when(filled($filters['organization_id'] ?? null), fn (Builder $q) => $q->where('organization_id', $filters['organization_id']))
            ->when(filled($filters['provider_id'] ?? null), fn (Builder $q) => $q->where('provider_id', $filters['provider_id']))
            ->orderBy('organization_id')->orderBy('policy_group_id')->orderBy('version_no')
            ->get()
            ->map(fn (CafeteriaServicePolicy $p): array => [
                'organization' => $p->organization?->name_en,
                'provider' => $p->provider?->name_en,
                'scope' => $p->cafeteria?->name_en ?? $p->network?->name_en ?? $p->provider?->name_en,
                'version' => $p->version_no,
                'subsidy' => (string) $p->daily_subsidy_amount,
                'employee_share' => (string) $p->employee_contribution_amount,
                'provider_price' => (string) $p->provider_price,
                'effective_from' => $p->effective_from?->toDateString(),
                'effective_to' => $p->effective_to?->toDateString(),
                'status' => $p->status?->value,
            ]);

        return ['columns' => ['organization', 'provider', 'scope', 'version', 'subsidy', 'employee_share', 'provider_price', 'effective_from', 'effective_to', 'status'], 'rows' => $rows->all()];
    }

    /** @return array{columns: list<string>, rows: list<array<string, mixed>>} */
    private function missingPolicy(?array $organizationIds): array
    {
        $summary = $this->health->summary($organizationIds);
        $rows = collect($summary['missing_policy'])->map(fn (array $row) => [
            'problem' => 'missing_policy', 'organization' => $row['organization']['name_en'] ?? null, 'detail' => $row['provider']['name_en'] ?? null,
        ])->merge(collect($summary['access_without_policy'])->map(fn (array $row) => [
            'problem' => 'access_without_policy', 'organization' => $row['organization']['name_en'] ?? null, 'detail' => $row['network']['name_en'] ?? null,
        ]))->merge(collect($summary['policy_without_access'])->map(fn (array $row) => [
            'problem' => 'policy_without_access', 'organization' => Organization::query()->whereKey($row['organization_id'])->value('name_en'), 'detail' => 'v'.$row['version_no'],
        ]));

        return ['columns' => ['problem', 'organization', 'detail'], 'rows' => $rows->values()->all()];
    }

    /** @return array{columns: list<string>, rows: list<array<string, mixed>>} */
    private function accessMatrix(array $filters, ?array $organizationIds): array
    {
        $rows = OrganizationCafeteriaAccess::query()
            ->with(['organization:id,name_en', 'network:id,name_en,provider_id', 'network.provider:id,name_en', 'primaryCafeteria:id,name_en'])
            ->withCount('locationExceptions')
            ->when($organizationIds !== null, fn (Builder $q) => $q->whereIn('organization_id', $organizationIds))
            ->when(filled($filters['organization_id'] ?? null), fn (Builder $q) => $q->where('organization_id', $filters['organization_id']))
            ->when(filled($filters['network_id'] ?? null), fn (Builder $q) => $q->where('cafeteria_service_network_id', $filters['network_id']))
            ->orderBy('organization_id')
            ->get()
            ->map(fn (OrganizationCafeteriaAccess $a): array => [
                'organization' => $a->organization?->name_en,
                'provider' => $a->network?->provider?->name_en,
                'network' => $a->network?->name_en,
                'primary_cafeteria' => $a->primaryCafeteria?->name_en,
                'cross_location' => $a->allow_cross_location_usage ? 'yes' : 'no',
                'exceptions' => $a->location_exceptions_count,
                'effective_from' => $a->effective_from?->toDateString(),
                'effective_to' => $a->effective_to?->toDateString(),
                'status' => $a->status?->value,
            ]);

        return ['columns' => ['organization', 'provider', 'network', 'primary_cafeteria', 'cross_location', 'exceptions', 'effective_from', 'effective_to', 'status'], 'rows' => $rows->all()];
    }

    /** @return array{columns: list<string>, rows: list<array<string, mixed>>} */
    private function settlementReport(array $filters): array
    {
        $rows = CafeteriaSettlement::query()
            ->with('provider:id,name_en')
            ->when(filled($filters['provider_id'] ?? null), fn (Builder $q) => $q->where('provider_id', $filters['provider_id']))
            ->whereDate('period_end', '>=', $filters['date_from'])
            ->whereDate('period_start', '<=', $filters['date_to'])
            ->orderByDesc('period_end')
            ->get()
            ->map(fn (CafeteriaSettlement $s): array => [
                'settlement' => $s->settlement_number,
                'provider' => $s->provider?->name_en,
                'effective_from' => $s->period_start?->toDateString(),
                'effective_to' => $s->period_end?->toDateString(),
                'transactions' => $s->transaction_count,
                'subsidy' => (string) $s->total_subsidy_amount,
                'employee_share' => (string) $s->total_employee_amount,
                'provider_total' => (string) $s->total_provider_amount,
                'status' => $s->status?->value,
            ]);

        return ['columns' => ['settlement', 'provider', 'effective_from', 'effective_to', 'transactions', 'subsidy', 'employee_share', 'provider_total', 'status'], 'rows' => $rows->all()];
    }

    /** @return Builder<CafeteriaTransaction> */
    private function transactions(array $filters, ?array $organizationIds): Builder
    {
        return CafeteriaTransaction::query()
            ->where('status', CafeteriaTransactionStatus::Accepted->value)
            ->whereDate('transaction_date', '>=', $filters['date_from'])
            ->whereDate('transaction_date', '<=', $filters['date_to'])
            ->when($organizationIds !== null, fn (Builder $q) => $q->whereIn('employee_organization_id', $organizationIds))
            ->when(filled($filters['organization_id'] ?? null), fn (Builder $q) => $q->where('employee_organization_id', $filters['organization_id']))
            ->when(filled($filters['provider_id'] ?? null), fn (Builder $q) => $q->where('provider_id', $filters['provider_id']))
            ->when(filled($filters['network_id'] ?? null), fn (Builder $q) => $q->where('cafeteria_service_network_id', $filters['network_id']))
            ->when(filled($filters['cafeteria_id'] ?? null), fn (Builder $q) => $q->where('cafeteria_provider_id', $filters['cafeteria_id']));
    }

    /**
     * Replaces id columns with display names.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  list<string>  $idColumns
     * @param  list<string>  $labels
     * @return Collection<int, array<string, mixed>>
     */
    private function label(Collection $rows, array $idColumns, array $labels): Collection
    {
        $lookups = [];
        foreach ($idColumns as $index => $column) {
            $ids = $rows->pluck($column)->filter()->unique()->values()->all();
            $lookups[$column] = match ($labels[$index]) {
                'organization' => Organization::query()->whereIn('id', $ids)->pluck('name_en', 'id'),
                'provider' => Provider::query()->whereIn('id', $ids)->pluck('name_en', 'id'),
                'network' => CafeteriaServiceNetwork::query()->whereIn('id', $ids)->pluck('name_en', 'id'),
                'cafeteria' => CafeteriaProvider::query()->withTrashed()->whereIn('id', $ids)->pluck('name_en', 'id'),
                'employee' => Employee::query()->whereIn('id', $ids)->get(['id', 'employee_number', 'full_name'])
                    ->mapWithKeys(fn (Employee $e) => [$e->id => trim($e->employee_number.' '.$e->full_name)]),
                'date' => null,
                default => null,
            };
        }

        return $rows->map(function (array $row) use ($idColumns, $labels, $lookups): array {
            foreach ($idColumns as $index => $column) {
                $value = $row[$column] ?? null;
                $row[$labels[$index]] = $lookups[$column] === null
                    ? ($value === null ? null : Carbon::parse($value)->toDateString())
                    : ($value === null ? null : ($lookups[$column][$value] ?? $value));
            }

            return $row;
        });
    }
}
