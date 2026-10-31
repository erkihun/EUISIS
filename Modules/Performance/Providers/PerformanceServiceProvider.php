<?php

declare(strict_types=1);

namespace Modules\Performance\Providers;

use App\Enums\CodeRuleEntityType;
use App\Services\CodeGeneration\CodeRuleTargetRegistry;
use App\Support\NotificationPresenter;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;
use Modules\DailyWork\Contracts\PerformanceItemSource;
use Modules\DailyWork\Contracts\ReadModels\DailyActivityItemRecord;
use Modules\Performance\Application\Notifications\PerformanceNotification;
use Modules\Performance\Application\Queries\PositionPerformanceQuery;
use Modules\Performance\Application\Services\DailyWorkPerformanceItems;
use Modules\Performance\Application\Services\EmployeePerformanceService;
use Modules\Performance\Contracts\EmployeePerformance;
use Modules\Performance\Contracts\PositionPerformance;
use Modules\Performance\Domain\Models\EmployeePerformanceAgreement;
use Modules\Performance\Domain\Models\EmployeePerformanceItem;
use Modules\Performance\Domain\Models\IndividualDevelopmentPlan;
use Modules\Performance\Domain\Models\Kpi;
use Modules\Performance\Domain\Models\KpiActual;
use Modules\Performance\Domain\Models\KpiContribution;
use Modules\Performance\Domain\Models\KpiPeriodTarget;
use Modules\Performance\Domain\Models\KpiTarget;
use Modules\Performance\Domain\Models\PerformanceAppeal;
use Modules\Performance\Domain\Models\PerformanceCalibrationItem;
use Modules\Performance\Domain\Models\PerformanceCalibrationSession;
use Modules\Performance\Domain\Models\PerformanceCascade;
use Modules\Performance\Domain\Models\PerformanceCheckin;
use Modules\Performance\Domain\Models\PerformanceCycle;
use Modules\Performance\Domain\Models\PerformanceEvidence;
use Modules\Performance\Domain\Models\PerformanceImprovementPlan;
use Modules\Performance\Domain\Models\PerformanceObjective;
use Modules\Performance\Domain\Models\PerformancePlan;
use Modules\Performance\Domain\Models\PerformancePlanScore;
use Modules\Performance\Domain\Models\PerformanceRatingBand;
use Modules\Performance\Domain\Models\PerformanceRatingScale;
use Modules\Performance\Domain\Models\PerformanceResult;
use Modules\Performance\Domain\Models\PerformanceReview;
use Modules\Performance\Domain\Models\PerformanceScoreAdjustment;
use Modules\Performance\Domain\Models\PerformanceTargetAmendment;
use Modules\Performance\Domain\Models\StrategicGoal;
use Modules\Performance\Domain\Models\StrategicGoalAllocation;

/**
 * Everything the application needs to know about the Performance (EPMS)
 * module (ADR 0017): its public contracts, Daily work's extension point it
 * implements, its notification renderer, and the names its records keep in
 * the database. Its routes stay in routes/performance.php, which names its
 * controllers.
 */
final class PerformanceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(EmployeePerformance::class, EmployeePerformanceService::class);
        $this->app->bind(PositionPerformance::class, PositionPerformanceQuery::class);
        // Performance implements Daily work's extension point (Daily work does not know Performance).
        $this->app->bind(PerformanceItemSource::class, DailyWorkPerformanceItems::class);
    }

    public function boot(): void
    {
        // Numbers its records take from code rules; a number already in use is skipped.
        CodeRuleTargetRegistry::registerCollisionCheck(CodeRuleEntityType::PerformanceAppeal, PerformanceAppeal::class, 'appeal_no');
        // Moved models keep their old class names in the database (audit logs, notifications, attachments).
        Relation::morphMap([
            'App\Models\EmployeePerformanceAgreement' => EmployeePerformanceAgreement::class,
            'App\Models\EmployeePerformanceItem' => EmployeePerformanceItem::class,
            'App\Models\IndividualDevelopmentPlan' => IndividualDevelopmentPlan::class,
            'App\Models\Kpi' => Kpi::class,
            'App\Models\KpiActual' => KpiActual::class,
            'App\Models\KpiContribution' => KpiContribution::class,
            'App\Models\KpiPeriodTarget' => KpiPeriodTarget::class,
            'App\Models\KpiTarget' => KpiTarget::class,
            'App\Models\PerformanceAppeal' => PerformanceAppeal::class,
            'App\Models\PerformanceCalibrationItem' => PerformanceCalibrationItem::class,
            'App\Models\PerformanceCalibrationSession' => PerformanceCalibrationSession::class,
            'App\Models\PerformanceCascade' => PerformanceCascade::class,
            'App\Models\PerformanceCheckin' => PerformanceCheckin::class,
            'App\Models\PerformanceCycle' => PerformanceCycle::class,
            'App\Models\PerformanceEvidence' => PerformanceEvidence::class,
            'App\Models\PerformanceImprovementPlan' => PerformanceImprovementPlan::class,
            'App\Models\PerformanceObjective' => PerformanceObjective::class,
            'App\Models\PerformancePlan' => PerformancePlan::class,
            'App\Models\PerformancePlanScore' => PerformancePlanScore::class,
            'App\Models\PerformanceRatingBand' => PerformanceRatingBand::class,
            'App\Models\PerformanceRatingScale' => PerformanceRatingScale::class,
            'App\Models\PerformanceResult' => PerformanceResult::class,
            'App\Models\PerformanceReview' => PerformanceReview::class,
            'App\Models\PerformanceScoreAdjustment' => PerformanceScoreAdjustment::class,
            'App\Models\PerformanceTargetAmendment' => PerformanceTargetAmendment::class,
            'App\Models\StrategicGoal' => StrategicGoal::class,
            'App\Models\StrategicGoalAllocation' => StrategicGoalAllocation::class,
        ]);

        // Performance attaches its item to Daily work's records.
        DailyActivityItemRecord::resolveRelationUsing('performanceItem', fn (DailyActivityItemRecord $item): BelongsTo => $item->belongsTo(EmployeePerformanceItem::class, 'employee_performance_item_id'));

        NotificationPresenter::registerRenderer('performance', PerformanceNotification::class);
    }
}
