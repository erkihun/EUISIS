<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\CafeteriaProvider;
use App\Models\CafeteriaProviderBranch;
use App\Models\CafeteriaProviderAssignment;
use App\Models\CafeteriaTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class CafeteriaProviderDashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $this->authorize('viewAny', CafeteriaProvider::class);

        $today = Carbon::today()->toDateString();

        // Summary KPIs
        $totalProviders = CafeteriaProvider::query()->count();
        $activeProviders = CafeteriaProvider::query()->where('is_active', true)->count();
        $totalBranches = CafeteriaProviderBranch::query()->whereNull('deleted_at')->count();
        $totalUsers = CafeteriaProviderAssignment::query()->where('is_active', true)->count();

        // Per-provider stats
        $todayTxByProvider = CafeteriaTransaction::query()
            ->select('cafeteria_provider_id', DB::raw('COUNT(*) as tx_count'), DB::raw('SUM(subsidy_amount_applied) as total_subsidy'))
            ->whereDate('transaction_date', $today)
            ->groupBy('cafeteria_provider_id')
            ->get()
            ->keyBy('cafeteria_provider_id');

        $providers = CafeteriaProvider::query()
            ->withCount(['branches', 'portalAssignments as active_users_count' => fn ($q) => $q->where('is_active', true)])
            ->orderByDesc('is_active')
            ->orderBy('name_en')
            ->get()
            ->map(function (CafeteriaProvider $p) use ($todayTxByProvider): array {
                $tx = $todayTxByProvider->get($p->id);

                return [
                    'id' => $p->id,
                    'code' => $p->code,
                    'name_en' => $p->name_en,
                    'name_am' => $p->name_am,
                    'is_active' => $p->is_active,
                    'branches_count' => $p->branches_count,
                    'active_users_count' => $p->active_users_count,
                    'today_tx' => $tx?->tx_count ?? 0,
                    'today_subsidy' => (float) ($tx?->total_subsidy ?? 0),
                ];
            })
            ->values()
            ->all();

        return Inertia::render('Cafeteria/Providers/ProviderDashboard', [
            'stats' => [
                'total_providers' => $totalProviders,
                'active_providers' => $activeProviders,
                'total_branches' => $totalBranches,
                'total_users' => $totalUsers,
            ],
            'providers' => $providers,
            'today' => $today,
            'can' => [
                'create' => $request->user()?->can('create', CafeteriaProvider::class) ?? false,
                'manageSettings' => $request->user()?->can('cafeteria_settings.update') ?? false,
            ],
        ]);
    }
}
