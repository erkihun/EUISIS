<?php

declare(strict_types=1);

namespace App\Console\Commands\Audit;

use Illuminate\Support\Facades\DB;

/**
 * Provider → network → cafeteria → organization access → service assignment →
 * policy consistency, as the scan resolves it. Read-only.
 */
class AuditCafeteriaConfiguration extends ReadOnlyAuditCommand
{
    protected $signature = 'cafeteria:audit-configuration {--json : Also print a machine-readable summary} {--strict : Exit 1 on any finding, not only HIGH}';

    protected $description = 'Read-only: report cafeteria network, access, assignment and policy misconfiguration';

    protected function checks(): array
    {
        $today = now()->toDateString();
        $effective = fn ($query, string $alias) => $query->where("{$alias}.status", 'active')
            ->whereDate("{$alias}.effective_from", '<=', $today)
            ->where(fn ($q) => $q->whereNull("{$alias}.effective_to")->orWhereDate("{$alias}.effective_to", '>=', $today));

        return [
            [
                'key' => 'cafeterias.network_provider', 'severity' => 'HIGH',
                'label' => 'Cafeterias whose provider differs from their network\'s provider',
                'why' => 'A network belongs to one provider; settlement and the provider portal follow that link.',
                'rows' => fn () => DB::table('cafeteria_providers as c')->join('cafeteria_service_networks as n', 'n.id', '=', 'c.cafeteria_service_network_id')
                    ->whereNotNull('c.provider_id')->whereColumn('c.provider_id', '!=', 'n.provider_id')->whereNull('c.deleted_at')->pluck('c.code'),
            ],
            [
                'key' => 'cafeterias.branch_network', 'severity' => 'HIGH',
                'label' => 'Branch cafeterias outside their main cafeteria\'s network',
                'why' => 'A branch serves the same network as its main cafeteria.',
                'rows' => fn () => DB::table('cafeteria_providers as c')->join('cafeteria_providers as m', 'm.id', '=', 'c.parent_cafeteria_id')
                    ->whereColumn('c.cafeteria_service_network_id', '!=', 'm.cafeteria_service_network_id')->whereNull('c.deleted_at')->pluck('c.code'),
            ],
            [
                'key' => 'networks.no_open_cafeteria', 'severity' => 'MEDIUM',
                'label' => 'Active networks with no open, active cafeteria',
                'why' => 'Organizations granted such a network have nowhere to eat.',
                'rows' => fn () => DB::table('cafeteria_service_networks as n')->where('n.status', 'active')->whereNull('n.deleted_at')
                    ->whereNotExists(fn ($q) => $q->from('cafeteria_providers as c')->whereColumn('c.cafeteria_service_network_id', 'n.id')
                        ->where('c.is_active', true)->where('c.operational_status', 'open')->whereNull('c.deleted_at'))
                    ->pluck('n.code'),
            ],
            [
                'key' => 'access.inactive_network', 'severity' => 'HIGH',
                'label' => 'Effective organization access to a network that is not active',
                'why' => 'Staff are told they have access, but every scan is refused.',
                'rows' => fn () => $effective(DB::table('organization_cafeteria_access as a'), 'a')
                    ->join('cafeteria_service_networks as n', 'n.id', '=', 'a.cafeteria_service_network_id')
                    ->where(fn ($q) => $q->where('n.status', '!=', 'active')->orWhereNotNull('n.deleted_at'))->pluck('a.id'),
            ],
            [
                'key' => 'access.primary_outside_network', 'severity' => 'HIGH',
                'label' => 'Access whose primary cafeteria is outside the granted network',
                'why' => 'The primary cafeteria must belong to the network the organization was granted.',
                'rows' => fn () => DB::table('organization_cafeteria_access as a')->join('cafeteria_providers as c', 'c.id', '=', 'a.primary_cafeteria_id')
                    ->whereIn('a.status', ['active', 'pending_approval'])
                    ->whereColumn('c.cafeteria_service_network_id', '!=', 'a.cafeteria_service_network_id')->pluck('a.id'),
            ],
            [
                'key' => 'access.overlap', 'severity' => 'MEDIUM',
                'label' => 'Organizations with more than one effective access row to one network',
                'why' => 'Two effective grants make the primary cafeteria and cross-location rule ambiguous.',
                'rows' => fn () => $effective(DB::table('organization_cafeteria_access as a'), 'a')
                    ->select('a.organization_id', 'a.cafeteria_service_network_id')->groupBy('a.organization_id', 'a.cafeteria_service_network_id')
                    ->havingRaw('count(*) > 1')->get()->map(fn ($row) => "{$row->organization_id} → {$row->cafeteria_service_network_id}"),
            ],
            [
                'key' => 'policies.overlap', 'severity' => 'HIGH',
                'label' => 'Active policies with overlapping dates for one scope',
                'why' => 'A scan must resolve exactly one policy; overlaps make the subsidy amount depend on row order.',
                'rows' => fn () => DB::table('cafeteria_service_policies as p')->join('cafeteria_service_policies as q', function ($join): void {
                    $join->on('q.scope_key', '=', 'p.scope_key')->whereColumn('q.id', '>', 'p.id');
                })->where('p.status', 'active')->where('q.status', 'active')
                    ->where(fn ($w) => $w->whereNull('p.effective_to')->orWhereColumn('p.effective_to', '>=', 'q.effective_from'))
                    ->where(fn ($w) => $w->whereNull('q.effective_to')->orWhereColumn('q.effective_to', '>=', 'p.effective_from'))
                    ->get(['p.id as a', 'q.id as b'])->map(fn ($row) => "{$row->a} ⟷ {$row->b}"),
            ],
            [
                'key' => 'policies.assignment_not_active', 'severity' => 'HIGH',
                'label' => 'Active policies whose service assignment is not active',
                'why' => 'The policy would price scans the assignment no longer allows.',
                'rows' => fn () => DB::table('cafeteria_service_policies as p')->join('cafeteria_service_assignments as s', 's.id', '=', 'p.cafeteria_service_assignment_id')
                    ->where('p.status', 'active')->where('s.status', '!=', 'active')->pluck('p.id'),
            ],
            [
                'key' => 'assignments.no_policy', 'severity' => 'MEDIUM',
                'label' => 'Effective service assignments with no policy in force today',
                'why' => 'Employees of the organization are refused at the counter with "no policy".',
                'rows' => fn () => $effective(DB::table('cafeteria_service_assignments as s'), 's')
                    ->whereNotExists(fn ($q) => $effective($q->from('cafeteria_service_policies as p')->whereColumn('p.cafeteria_service_assignment_id', 's.id'), 'p'))
                    ->pluck('s.id'),
            ],
            [
                'key' => 'providers.no_portal_user', 'severity' => 'LOW',
                'label' => 'Providers with an active network but no active portal user',
                'why' => 'Nobody at the provider can see transactions or confirm settlements.',
                'rows' => fn () => DB::table('cafeteria_service_networks as n')->where('n.status', 'active')->whereNull('n.deleted_at')
                    ->whereNotExists(fn ($q) => $q->from('provider_users as u')->whereColumn('u.provider_id', 'n.provider_id')->where('u.status', 'active'))
                    ->distinct()->pluck('n.provider_id'),
            ],
        ];
    }
}
