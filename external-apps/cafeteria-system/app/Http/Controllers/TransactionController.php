<?php

declare(strict_types=1);

namespace CafeteriaSystem\Http\Controllers;

use CafeteriaSystem\Models\CafeteriaServiceTransaction;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

class TransactionController extends Controller
{
    public function index(Request $request): Response
    {
        $providerId = (string) $request->user('cafeteria')->provider_id;
        $search = $request->string('search')->toString();

        return Inertia::render('Transactions/Index', [
            'transactions' => CafeteriaServiceTransaction::query()
                ->where('provider_id', $providerId)
                ->when($search !== '', fn ($query) => $query
                    ->where('employee_number', 'like', '%'.$search.'%'))
                ->orderByDesc('served_at')
                ->paginate(50)
                ->withQueryString(),
            'filters' => $request->only(['search']),
        ]);
    }
}
