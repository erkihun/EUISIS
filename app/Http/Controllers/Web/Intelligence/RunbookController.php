<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Intelligence;

use App\Http\Controllers\Controller;
use App\Models\IntelligenceIncident;
use App\Services\Intelligence\IntelligenceLabels;
use App\Services\Intelligence\IntelligenceSettings;
use App\Services\Intelligence\System\RunbookCatalog;
use Inertia\Inertia;
use Inertia\Response;

/** Read-only runbooks (brief §49–51). Nothing here runs anything; execution stays disabled. */
final class RunbookController extends Controller
{
    public function __construct(private readonly IntelligenceSettings $settings) {}

    public function index(): Response
    {
        $open = IntelligenceIncident::query()->whereIn('status', IntelligenceIncident::ACTIVE_STATUSES)->get(['rule'])->pluck('rule')->countBy()->all();

        return Inertia::render('Intelligence/Runbooks/Index', [
            'labels' => IntelligenceLabels::dictionary(),
            'runbooks' => array_map(fn (array $book): array => [
                ...$book,
                'href' => route('intelligence.runbooks.show', $book['key']),
                'active_incidents' => array_sum(array_map(fn (string $rule): int => (int) ($open[$rule] ?? 0), $book['rules'])),
            ], RunbookCatalog::all()),
            'execution_enabled' => $this->settings->runbookExecutionEnabled(),
        ]);
    }

    public function show(string $runbook): Response
    {
        abort_unless(RunbookCatalog::has($runbook), 404);
        $book = RunbookCatalog::get($runbook);

        return Inertia::render('Intelligence/Runbooks/Show', [
            'labels' => IntelligenceLabels::dictionary(),
            'runbook' => $book,
            'incidents' => IntelligenceIncident::query()->whereIn('rule', $book['rules'])->whereIn('status', IntelligenceIncident::ACTIVE_STATUSES)
                ->orderByDesc('last_seen_at')->limit(10)->get(['id', 'reference', 'severity', 'status', 'component', 'last_seen_at'])
                ->map(fn (IntelligenceIncident $i): array => ['id' => $i->id, 'reference' => $i->reference, 'severity' => $i->severity, 'status' => $i->status, 'component' => $i->component, 'last_seen_at' => $i->last_seen_at->toIso8601String(), 'href' => route('intelligence.incidents.show', $i->id)])
                ->all(),
            'execution_enabled' => $this->settings->runbookExecutionEnabled(),
        ]);
    }
}
