<?php

declare(strict_types=1);

namespace Modules\Cafeteria\Http\Controllers\ProviderPortal;

use App\Http\Controllers\Controller;
use App\Services\ProviderPortal\ProviderPortalContext;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Cafeteria\Application\Services\Portal\CafeteriaPortalSelector;
use Modules\Cafeteria\Domain\Enums\CafeteriaTransactionStatus;
use Modules\Cafeteria\Domain\Models\CafeteriaFoodOrder;
use Modules\Cafeteria\Domain\Models\CafeteriaMenu;
use Modules\Cafeteria\Domain\Models\CafeteriaTransaction;
use Modules\Cafeteria\Http\Controllers\ProviderPortal\Concerns\FormatsProviderPortalData;
use Modules\Cafeteria\Http\Resources\CafeteriaTransactionResource;
use Modules\Cafeteria\Http\Resources\ProviderPortal\CafeteriaFoodOrderResource;

class ProviderDashboardController extends Controller
{
    use FormatsProviderPortalData;

    public function __invoke(Request $request, ProviderPortalContext $context): Response
    {
        $provider = app(CafeteriaPortalSelector::class)->selectedProvider($request);
        abort_if($provider === null, 403, __('provider-portal.not_assigned'));

        $today = Carbon::today()->toDateString();

        $transactions = CafeteriaTransaction::query()
            ->with(['employee.currentAssignment.organization', 'employee.currentAssignment.position', 'provider', 'consumedDays'])
            ->where('cafeteria_provider_id', $provider->id)
            ->whereDate('transaction_date', $today)
            ->orderByDesc('scanned_at')
            ->limit(8)
            ->get();

        $acceptedQuery = CafeteriaTransaction::query()
            ->where('cafeteria_provider_id', $provider->id)
            ->whereDate('transaction_date', $today)
            ->where('status', CafeteriaTransactionStatus::Accepted->value);

        $orders = CafeteriaFoodOrder::query()
            ->with(['employee', 'menu'])
            ->where('cafeteria_provider_id', $provider->id)
            ->whereDate('order_date', $today)
            ->orderByDesc('ordered_at')
            ->limit(8)
            ->get();

        return Inertia::render('Cafeteria/Portal/Dashboard', [
            ...$this->portalPayload($request, $context, $provider),
            'stats' => [
                'today_scans' => (clone $acceptedQuery)->count(),
                'today_subsidy' => (float) (clone $acceptedQuery)->sum('subsidy_amount_applied'),
                'pending_orders' => CafeteriaFoodOrder::query()
                    ->where('cafeteria_provider_id', $provider->id)
                    ->where('status', 'pending')
                    ->count(),
                'active_menus' => CafeteriaMenu::query()
                    ->where('cafeteria_provider_id', $provider->id)
                    ->where('status', 'published')
                    ->count(),
            ],
            'recent_transactions' => CafeteriaTransactionResource::collection($transactions)->resolve(),
            'recent_orders' => CafeteriaFoodOrderResource::collection($orders)->resolve(),
        ]);
    }
}
