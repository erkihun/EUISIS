<?php

declare(strict_types=1);

namespace CafeteriaSystem\Http\Controllers;

use CafeteriaSystem\Models\CafeteriaApiLog;
use CafeteriaSystem\Models\CafeteriaServiceTransaction;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $providerId = (string) $request->user('cafeteria')->provider_id;
        $today = now()->toDateString();

        return Inertia::render('Dashboard/Index', [
            'stats' => [
                'served_today' => CafeteriaServiceTransaction::query()
                    ->where('provider_id', $providerId)->where('status', 'served')
                    ->whereDate('served_at', $today)->count(),
                'served_this_month' => CafeteriaServiceTransaction::query()
                    ->where('provider_id', $providerId)->where('status', 'served')
                    ->whereBetween('served_at', [now()->startOfMonth(), now()->endOfMonth()])->count(),
                'api_failures_today' => CafeteriaApiLog::query()
                    ->where('success', false)->whereDate('requested_at', $today)->count(),
            ],
            'recent' => CafeteriaServiceTransaction::query()
                ->where('provider_id', $providerId)
                ->orderByDesc('served_at')->limit(10)
                ->get(['transaction_number', 'employee_number', 'employee_name', 'status', 'served_at']),
        ]);
    }

    public function apiLogs(): Response
    {
        return Inertia::render('ApiLogs/Index', [
            'logs' => CafeteriaApiLog::query()
                ->orderByDesc('requested_at')->paginate(50),
        ]);
    }

    public function settings(): Response
    {
        return Inertia::render('Settings/Index', [
            // Never expose the token itself — only whether it is configured.
            'integration' => [
                'base_url' => config('euisis.base_url'),
                'token_configured' => config('euisis.token') !== '',
                'timeout' => config('euisis.timeout'),
                'provider_code' => config('euisis.provider_code'),
                'required_scopes' => config('euisis.required_scopes'),
            ],
        ]);
    }
}
