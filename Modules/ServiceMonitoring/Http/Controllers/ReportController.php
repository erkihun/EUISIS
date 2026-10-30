<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\ServiceMonitoring\Application\Services\ComplianceService;
use Modules\ServiceMonitoring\Application\Services\ConsolidationService;
use Modules\ServiceMonitoring\Application\Services\ParticipantResolver;
use Modules\ServiceMonitoring\Application\Services\PeriodDirectory;
use Modules\ServiceMonitoring\Application\Services\ServiceMonitoringAccess;
use Modules\ServiceMonitoring\Domain\Enums\ReportRange;
use Modules\ServiceMonitoring\Domain\Models\DigitalService;
use Modules\ServiceMonitoring\Domain\Models\FiscalYear;
use Modules\ServiceMonitoring\Domain\Models\Participant;
use Modules\ServiceMonitoring\Domain\Models\ReportingPeriod;

/**
 * Reports within the viewer's scope, without names: compliance per period,
 * accountability (I.2), verified digital services, catalog gaps and
 * standard performance, for a quarter or a cumulative range.
 */
final class ReportController extends Controller
{
    public function __invoke(
        Request $request,
        PeriodDirectory $periods,
        ParticipantResolver $participants,
        ComplianceService $compliance,
        ConsolidationService $consolidation,
        ServiceMonitoringAccess $access,
    ): Response {
        abort_unless($access->canViewReports($request->user()), 403);
        $user = $request->user();
        $current = $periods->current();
        $year = FiscalYear::query()->find($request->string('fiscal_year_id')->toString()) ?? $current?->fiscalYear ?? FiscalYear::query()->orderByDesc('ec_year')->first();
        $quarter = max(1, min(4, (int) ($request->input('quarter') ?: ($current?->quarter ?? 1))));
        $range = ReportRange::tryFrom($request->string('range')->toString()) ?? ReportRange::Quarter;

        if ($year === null) {
            return Inertia::render('ServiceMonitoring/Reports/Index', ['years' => [], 'selection' => null, 'report' => null, 'compliance' => [], 'catalog_gaps' => []]);
        }
        $visibleIds = $access->isCitywide($user) ? null : $access->scopeParticipants(Participant::query(), $user)->pluck('id')->map(fn ($id): string => (string) $id)->all();

        return Inertia::render('ServiceMonitoring/Reports/Index', [
            'years' => $periods->years(),
            'selection' => ['fiscal_year_id' => $year->id, 'quarter' => $quarter, 'range' => $range->value],
            'compliance' => $year->periods->map(function (ReportingPeriod $period) use ($participants, $compliance, $user): array {
                $expected = $participants->visibleExpectedFor($period, $user)->get();

                return ['period_id' => $period->id, 'label' => $period->label(), 'tally' => ComplianceService::tally($compliance->forPeriod($period, $expected)), 'participants' => $expected->count()];
            })->values()->all(),
            'report' => $consolidation->report($year, $range, $quarter, $visibleIds, false),
            'catalog_gaps' => DigitalService::query()->where('is_catalog_gap', true)
                ->whereHas('submission', fn ($q) => $access->scopeSubmissions($q->where('is_current', true)->whereHas('period', fn ($p) => $p->where('fiscal_year_id', $year->id)), $user))
                ->with('submission.organization:id,name_en,name_am', 'submission.period')
                ->orderBy('service_name_am')->limit(500)->get()
                ->map(fn (DigitalService $d): array => [
                    'id' => $d->id, 'service_name_am' => $d->service_name_am, 'service_name_en' => $d->service_name_en, 'level' => $d->level->value,
                    'verification_status' => $d->verification_status->value, 'quarter' => $d->submission->period->quarter,
                    'organization' => ['name_en' => $d->submission->organization?->name_en, 'name_am' => $d->submission->organization?->name_am],
                ])->all(),
        ]);
    }
}
