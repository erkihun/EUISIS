<?php

declare(strict_types=1);

namespace CafeteriaSystem\Http\Controllers;

use CafeteriaSystem\Models\CafeteriaSettlement;
use CafeteriaSystem\Services\CafeteriaReportService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

class SettlementController extends Controller
{
    public function __construct(private readonly CafeteriaReportService $reports) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Settlements/Index', [
            'settlements' => CafeteriaSettlement::query()
                ->where('provider_id', (string) $request->user('cafeteria')->provider_id)
                ->orderByDesc('period_start')
                ->paginate(25),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
        ]);

        $this->reports->generateSettlement(
            (string) $request->user('cafeteria')->provider_id,
            Carbon::parse($validated['period_start'])->startOfDay(),
            Carbon::parse($validated['period_end'])->endOfDay(),
        );

        return back()->with(['message' => 'Settlement generated.', 'type' => 'success']);
    }
}
