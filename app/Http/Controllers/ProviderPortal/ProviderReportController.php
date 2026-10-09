<?php

declare(strict_types=1);

namespace App\Http\Controllers\ProviderPortal;

use App\Enums\CafeteriaTransactionStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\ProviderPortal\Concerns\FormatsProviderPortalData;
use App\Models\CafeteriaFoodOrder;
use App\Models\CafeteriaTransaction;
use App\Services\ProviderPortal\ProviderPortalContext;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class ProviderReportController extends Controller
{
    use FormatsProviderPortalData;

    public function index(Request $request, ProviderPortalContext $context): Response
    {
        $provider = $context->selectedProvider($request);
        abort_if($provider === null, 403, __('provider-portal.not_assigned'));

        $from = $request->date('date_from')?->toDateString() ?? Carbon::today()->startOfMonth()->toDateString();
        $to = $request->date('date_to')?->toDateString() ?? Carbon::today()->toDateString();

        $transactions = CafeteriaTransaction::query()
            ->where('cafeteria_provider_id', $provider->id)
            ->whereBetween('transaction_date', [$from, $to])
            ->where('status', CafeteriaTransactionStatus::Accepted->value);

        return Inertia::render('Cafeteria/Portal/Reports/Index', [
            ...$this->portalPayload($request, $context, $provider),
            'filters' => ['date_from' => $from, 'date_to' => $to],
            'summary' => [
                'scan_count' => (clone $transactions)->count(),
                'subsidy_total' => (float) (clone $transactions)->sum('subsidy_amount_applied'),
                'employee_payable_total' => (float) (clone $transactions)->sum('employee_payable_amount'),
                'order_count' => CafeteriaFoodOrder::query()
                    ->where('cafeteria_provider_id', $provider->id)
                    ->whereBetween('order_date', [$from, $to])
                    ->count(),
            ],
        ]);
    }
}
