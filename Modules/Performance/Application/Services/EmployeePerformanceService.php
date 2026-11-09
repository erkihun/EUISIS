<?php

declare(strict_types=1);

namespace Modules\Performance\Application\Services;

use App\Models\EmployeeAssignment;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Modules\Performance\Contracts\Data\AgreementStatus;
use Modules\Performance\Contracts\Data\AppealStatus;
use Modules\Performance\Contracts\Data\ResultStatus;
use Modules\Performance\Contracts\Data\ReviewStatus;
use Modules\Performance\Contracts\Data\ReviewType;
use Modules\Performance\Contracts\EmployeePerformance;
use Modules\Performance\Domain\Enums\KpiAggregation;
use Modules\Performance\Domain\Enums\KpiHealth;
use Modules\Performance\Domain\Models\EmployeePerformanceAgreement;
use Modules\Performance\Domain\Models\PerformanceAppeal;
use Throwable;

/** An employee's performance for My Portal and transfers (moved here from the portal controller). */
final class EmployeePerformanceService implements EmployeePerformance
{
    public function __construct(
        private readonly EpmsSettings $settings,
        private readonly EmployeeAgreementService $agreements,
        private readonly EmployeeScoreCalculator $calculator,
        private readonly PerformanceAggregationService $aggregation,
        private readonly PerformanceReviewService $reviews,
    ) {}

    public function closeForAssignmentChange(EmployeeAssignment $oldAssignment, EmployeeAssignment $newAssignment, User $actor): void
    {
        $this->agreements->closeForAssignmentChange($oldAssignment, $newAssignment, $actor);
    }

    /**
     * The current agreement, per-KPI progress from measured actuals (never a
     * preliminary total score), the result once it is released, and the
     * steps waiting for the employee.
     */
    public function portalSummary(User $user, string $employeeId): ?array
    {
        if (! $this->settings->enabled() || ! $user->can('employee_performance_agreements.view_own')) {
            return null;
        }

        try {
            // The newest agreement that is still running, else the newest one.
            $agreements = EmployeePerformanceAgreement::query()->where('employee_id', $employeeId)
                ->with('cycle')->orderByDesc('effective_from')->limit(10)->get();
            $agreement = $agreements->first(fn (EmployeePerformanceAgreement $a) => $a->status !== AgreementStatus::Closed) ?? $agreements->first();
            if ($agreement === null) {
                return ['agreement' => null, 'kpis' => [], 'counts' => null, 'result' => null, 'actions' => []];
            }

            $asOf = now()->startOfDay();
            $kpis = collect($this->calculator->trace($agreement)['items'])
                ->map(fn (array $item): array => [
                    'code' => $item['kpi_code'],
                    'name_en' => $item['kpi_name_en'],
                    'name_am' => $item['kpi_name_am'],
                    'weight' => $item['weight'],
                    'achievement' => $item['achievement_status'] === 'OK' ? $item['achievement'] : null,
                    'health' => $this->aggregation->healthFor(
                        $item['achievement_status'] === 'OK' ? $item['achievement'] : null,
                        KpiAggregation::from($item['aggregation']['method']),
                        $agreement->effective_from, $agreement->effective_to, $asOf,
                    )->value,
                ])
                ->sortByDesc(fn (array $kpi) => (float) $kpi['weight'])->values();

            $actions = [];
            if ($agreement->status === AgreementStatus::PendingEmployeeReview) {
                $actions[] = ['key' => 'acknowledge'];
            }
            foreach ([ReviewType::MidYear, ReviewType::YearEnd] as $type) {
                if (! $this->reviews->canSubmitSelfAssessment($agreement, $type, $user)) {
                    continue;
                }
                $status = $agreement->reviews()->where('review_type', $type->value)->value('status');
                if ($status === ReviewStatus::Returned) {
                    $actions[] = ['key' => 'review_returned', 'review' => $type->value];
                } elseif ($this->reviews->selfAssessmentDue($agreement, $type)) {
                    $actions[] = ['key' => 'self_assessment', 'review' => $type->value];
                }
            }

            // Scores reach the employee only once released (as on My Performance).
            $result = $agreement->results()->where('is_current', true)->first();
            $released = $result !== null && $result->status === ResultStatus::Released ? $result : null;
            if ($released !== null) {
                $appealUntil = $released->released_at?->copy()->addDays($this->settings->appealWindowDays())->endOfDay();
                $appealed = PerformanceAppeal::query()->where('result_id', $released->getKey())
                    ->whereIn('status', [AppealStatus::Submitted, AppealStatus::UnderReview])->exists();
                if ($user->can('performance_appeals.create') && ! $appealed && $appealUntil !== null && now()->lte($appealUntil)) {
                    $actions[] = ['key' => 'result_released'];
                }
            }

            return [
                'agreement' => [
                    'id' => $agreement->getKey(),
                    'status' => $agreement->status->value,
                    'cycle' => ['name_en' => $agreement->cycle?->name_en, 'name_am' => $agreement->cycle?->name_am],
                    'effective_from' => $agreement->effective_from?->toDateString(),
                    'effective_to' => $agreement->effective_to?->toDateString(),
                ],
                'kpis' => $kpis->take(5)->all(),
                'counts' => [
                    'total' => $kpis->count(),
                    'on_track' => $kpis->where('health', KpiHealth::OnTrack->value)->count(),
                    'attention' => $kpis->whereIn('health', [KpiHealth::AtRisk->value, KpiHealth::OffTrack->value])->count(),
                    'not_reported' => $kpis->where('health', KpiHealth::NotReported->value)->count(),
                ],
                'result' => $released === null ? null : [
                    'final_score' => $released->final_score,
                    'rating_en' => $released->rating_label_en,
                    'rating_am' => $released->rating_label_am,
                ],
                'actions' => $actions,
            ];
        } catch (Throwable $exception) {
            Log::warning('Employee dashboard performance panel unavailable', [
                'employee_id' => $employeeId,
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }
    }
}
