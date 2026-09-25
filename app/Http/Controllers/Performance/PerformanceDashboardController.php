<?php

declare(strict_types=1);

namespace App\Http\Controllers\Performance;

use App\Enums\Performance\PlanStatus;
use App\Enums\Performance\PlanType;
use App\Enums\Performance\ResultStatus;
use App\Models\EmployeePerformanceAgreement;
use App\Models\PerformanceCycle;
use App\Models\PerformancePlan;
use App\Models\PerformancePlanScore;
use App\Models\PerformanceResult;
use App\Models\PerformanceReview;
use App\Services\OrganizationScope\OrganizationScopeService;
use App\Services\Performance\EpmsAccess;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Organization / unit dashboard. Only persisted data: cached plan scores
 * (recomputed by a queued job), counted agreements/reviews, finalized result
 * distribution. No trends, benchmarks or placeholder numbers — an unmeasured
 * value shows as "not calculated".
 */
class PerformanceDashboardController extends PerformanceController
{
    public function __construct(private readonly OrganizationScopeService $scope, private readonly EpmsAccess $access) {}

    public function index(Request $request): Response
    {
        $this->ensureEnabled();
        $user = $request->user();
        abort_unless($user->can('performance_reports.view') || $user->can('employee_performance_agreements.manage'), 403);

        $cycles = PerformanceCycle::query()
            ->where(fn ($q) => $q->whereNull('organization_id')->orWhereIn('organization_id', $this->scope->allowedOrganizationIds($user)))
            ->orderByDesc('is_current')
            ->orderByDesc('start_date')
            ->get(['id', 'code', 'name_en', 'name_am', 'status', 'start_date', 'end_date', 'is_current']);

        $requestedCycleId = (string) $request->query('cycle_id', '');
        $cycleId = (string) ($cycles->firstWhere('id', $requestedCycleId)?->getKey() ?? $cycles->first()?->getKey());

        $plans = $this->scope->applyOrganizationScope(PerformancePlan::query(), $user)
            ->where('cycle_id', $cycleId)->where('status', PlanStatus::Published->value)
            ->whereIn('plan_type', [PlanType::Organization->value, PlanType::Unit->value])
            ->with(['organization:id,name_en,name_am', 'organizationUnit:id,name_en,name_am'])->limit(200)->get();

        $scores = PerformancePlanScore::query()->whereIn('performance_plan_id', $plans->modelKeys())
            ->orderByDesc('as_of')->get()->unique('performance_plan_id')->keyBy('performance_plan_id');

        $planRow = fn (PerformancePlan $p) => [
            'id' => $p->getKey(), 'title' => $p->title, 'type' => $p->plan_type->value,
            'organization' => ['name_en' => $p->organization?->name_en, 'name_am' => $p->organization?->name_am],
            'unit' => $p->organizationUnit ? ['name_en' => $p->organizationUnit->name_en, 'name_am' => $p->organizationUnit->name_am] : null,
            'score' => $scores->get($p->getKey())?->score,
            'as_of' => $scores->get($p->getKey())?->as_of?->toDateString(),
        ];

        $atRisk = [];
        foreach ($scores as $score) {
            foreach ($score->trace_json['objectives'] ?? [] as $objective) {
                foreach ($objective['targets'] ?? [] as $target) {
                    if (in_array($target['health'] ?? null, ['AT_RISK', 'OFF_TRACK', 'NOT_REPORTED'], true)) {
                        $atRisk[] = ['plan_id' => $score->performance_plan_id, 'objective' => $objective['code'], 'kpi_code' => $target['kpi_code'],
                            'kpi_name_en' => $target['kpi_name_en'], 'kpi_name_am' => $target['kpi_name_am'] ?? null, 'health' => $target['health'],
                            'achievement' => $target['achievement'], 'target' => $target['target'], 'actual' => $target['actual']];
                    }
                }
            }
        }

        $agreements = $this->access->constrainAgreements(EmployeePerformanceAgreement::query(), $user)->where('cycle_id', $cycleId);
        $agreementIds = (clone $agreements)->select('id');

        $reviewCounts = PerformanceReview::query()->whereIn('agreement_id', $agreementIds)
            ->toBase()->selectRaw('review_type, status, count(*) as total')->groupBy('review_type', 'status')->get(); // raw strings, not enum casts

        return Inertia::render('Performance/Dashboard', [
            'cycles' => $cycles->toArray(),
            'cycleId' => $cycleId,
            'organizationPlans' => $plans->where('plan_type', PlanType::Organization)->values()->map($planRow)->all(),
            'unitPlans' => $plans->where('plan_type', PlanType::Unit)->values()->map($planRow)->sortBy(fn ($row) => $row['score'] === null ? PHP_INT_MAX : (float) $row['score'])->values()->take(20)->all(),
            'atRisk' => array_slice($atRisk, 0, 25),
            'agreementStatus' => (clone $agreements)->toBase()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->all(),
            'agreementTotal' => (clone $agreements)->count(),
            'reviews' => $reviewCounts->groupBy('review_type')->map(fn ($rows) => $rows->pluck('total', 'status')->all())->all(),
            'distribution' => PerformanceResult::query()->whereIn('agreement_id', $agreementIds)->where('is_current', true)
                ->whereIn('status', [ResultStatus::Finalized->value, ResultStatus::PendingRelease->value, ResultStatus::Released->value])
                ->selectRaw('rating_label_en, rating_label_am, count(*) as total')->groupBy('rating_label_en', 'rating_label_am')->get()->toArray(),
            'can' => ['recalculate' => $user->can('performance_reports.view')],
        ]);
    }
}
