<?php

declare(strict_types=1);

namespace App\Http\Controllers\ServiceMonitoring;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Http\Requests\ServiceMonitoring\SaveServiceMonitoringPeriodRequest;
use App\Models\ServiceMonitoringPeriod;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/** Reporting periods: the fiscal year and 3/6/9/12-month window a report belongs to. */
class ServiceMonitoringPeriodController extends Controller
{
    public function __construct(private readonly WriteAuditLogAction $audit) {}

    public function index(): Response
    {
        $this->authorize('viewAny', ServiceMonitoringPeriod::class);

        $periods = ServiceMonitoringPeriod::query()
            ->withCount('submissions')
            ->orderByDesc('fiscal_year')
            ->orderByDesc('created_at')
            ->paginate(25)
            ->through(fn (ServiceMonitoringPeriod $period): array => $this->present($period) + ['submissions_count' => $period->submissions_count]);

        return Inertia::render('ServiceMonitoring/Periods/Index', ['periods' => $periods]);
    }

    public function create(): Response
    {
        $this->authorize('create', ServiceMonitoringPeriod::class);

        return Inertia::render('ServiceMonitoring/Periods/Create');
    }

    public function store(SaveServiceMonitoringPeriodRequest $request): RedirectResponse
    {
        $period = ServiceMonitoringPeriod::query()->create($request->periodAttributes() + ['created_by' => $request->user()->getKey()]);
        $this->audit->execute(AuditEventType::ServiceMonitoringPeriodSaved, $request->user(), $period, null, null, $period->only(['fiscal_year', 'period_type', 'status']), request: $request);

        return to_route('service-monitoring.periods.index')->with('success', __('service-monitoring.flash.period_saved'));
    }

    public function edit(ServiceMonitoringPeriod $period): Response
    {
        $this->authorize('update', $period);

        return Inertia::render('ServiceMonitoring/Periods/Edit', ['period' => $this->present($period)]);
    }

    public function update(SaveServiceMonitoringPeriodRequest $request, ServiceMonitoringPeriod $period): RedirectResponse
    {
        $before = $period->only(['fiscal_year', 'period_type', 'status', 'due_date']);
        $period->update($request->periodAttributes());
        $this->audit->execute(AuditEventType::ServiceMonitoringPeriodSaved, $request->user(), $period, null, $before, $period->only(['fiscal_year', 'period_type', 'status', 'due_date']), request: $request);

        return to_route('service-monitoring.periods.index')->with('success', __('service-monitoring.flash.period_saved'));
    }

    /** @return array<string, mixed> */
    private function present(ServiceMonitoringPeriod $period): array
    {
        return [
            'id' => $period->id,
            'fiscal_year' => $period->fiscal_year,
            'period_type' => $period->period_type->value,
            'name_am' => $period->name_am,
            'name_en' => $period->name_en,
            'due_date' => $period->due_date?->toDateString(),
            'status' => $period->status->value,
        ];
    }
}
