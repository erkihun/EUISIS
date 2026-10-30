<?php

declare(strict_types=1);

namespace Modules\Cafeteria\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Cafeteria\Application\Services\CafeteriaProviderAccessService;
use Modules\Cafeteria\Application\Services\CafeteriaReportService;
use Modules\Cafeteria\Application\Services\Policy\CafeteriaConfigurationHealthService;
use Modules\Cafeteria\Domain\Models\CafeteriaProvider;
use Modules\Cafeteria\Domain\Models\CafeteriaTransaction;

class CafeteriaDashboardController extends Controller
{
    public function __construct(
        private readonly CafeteriaReportService $reportService,
        private readonly CafeteriaProviderAccessService $providerAccess,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', CafeteriaTransaction::class);

        $today = Carbon::today();

        $todaySummary = $this->reportService->getDailyProviderSummary($today, $request->user());
        $monthSummary = $this->reportService->getPeriodSummary($today->copy()->startOfMonth(), $today, user: $request->user());

        $activeProviderQuery = CafeteriaProvider::query()->where('is_active', true);
        $this->providerAccess->filterProviderScopedQuery($request->user(), $activeProviderQuery, 'id');

        $todayQuery = CafeteriaTransaction::query()->whereDate('transaction_date', $today);
        $this->providerAccess->filterProviderScopedQuery($request->user(), $todayQuery);

        $activeProviders = $activeProviderQuery->count();
        $todayTotal = (clone $todayQuery)->count();
        $todayExtra = (clone $todayQuery)->where('is_extra_scan', true)->count();

        // Missing / expiring / mismatched policy and access, for those who can act on it.
        $health = null;
        if ($request->user()->can('cafeteria_policies.view')) {
            $scope = app(OrganizationScopeService::class);
            $health = app(CafeteriaConfigurationHealthService::class)->summary(
                $scope->isUnrestricted($request->user()) ? null : array_values(array_map('strval', $scope->allowedOrganizationIds($request->user()))),
            );
        }

        return Inertia::render('Cafeteria/Dashboard', [
            'stats' => [
                'active_providers' => $activeProviders,
                'today_transactions' => $todayTotal,
                'today_extra_scans' => $todayExtra,
                'month_total_subsidy' => $monthSummary['total_subsidy'],
                'month_transactions' => $monthSummary['total_transactions'],
            ],
            'today_by_provider' => $todaySummary,
            'health' => $health,
        ]);
    }
}
