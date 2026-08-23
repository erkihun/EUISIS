<?php

declare(strict_types=1);

namespace CafeteriaSystem\Http\Controllers;

use CafeteriaSystem\Models\CafeteriaSubsidyLedger;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Subsidy ledger — credits granted and debits consumed, per employee.
 *
 * Scoped to the acting user's provider and accessible cafeterias, so one
 * provider can never read another's subsidy history.
 */
class LedgerController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user('cafeteria');
        $employeeNumber = $request->string('employee_number')->toString();

        $query = CafeteriaSubsidyLedger::query()
            ->where('provider_id', $user->provider_id)
            ->when(! $user->isProviderAdmin(), fn ($inner) => $inner
                ->whereIn('cafeteria_id', $user->accessibleCafeteriaIds()))
            ->when($employeeNumber !== '', fn ($inner) => $inner
                ->where('employee_number', 'like', '%'.$employeeNumber.'%'))
            ->when($request->filled('date_from'), fn ($inner) => $inner
                ->whereDate('entry_date', '>=', $request->date('date_from')))
            ->when($request->filled('date_to'), fn ($inner) => $inner
                ->whereDate('entry_date', '<=', $request->date('date_to')));

        // Running balance for the filtered employee, when one is selected.
        $balance = $employeeNumber === '' ? null : (float) (clone $query)
            ->selectRaw("coalesce(sum(case when entry_type = 'credit' then amount else -amount end), 0) as total")
            ->value('total');

        return Inertia::render('Ledger/Index', [
            'entries' => $query->clone()
                ->orderByDesc('entry_date')
                ->paginate(50)
                ->withQueryString(),
            'filters' => $request->only(['employee_number', 'date_from', 'date_to']),
            'balance' => $balance,
        ]);
    }
}
