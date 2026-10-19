<?php

declare(strict_types=1);

namespace App\Services\Intelligence;

use App\Services\Intelligence\Contracts\AreaAnalysis;

/**
 * Which class computes each area. Classes are named here before they exist:
 * an area whose class is not (yet) in the codebase renders the honest
 * "no analysis available" frame instead of a placeholder figure, so the two
 * intelligence domains can be built independently.
 */
final class IntelligenceAreaRegistry
{
    /** @var array<string, class-string<AreaAnalysis>> */
    public const ANALYSES = [
        IntelligenceArea::Overview->value => Business\OverviewAnalysis::class,
        IntelligenceArea::Executive->value => Business\ExecutiveAnalysis::class,
        IntelligenceArea::Workforce->value => Business\WorkforceAnalysis::class,
        IntelligenceArea::Service->value => Business\ServiceAnalysis::class,
        IntelligenceArea::PublicVoice->value => Business\PublicVoiceAnalysis::class,
        IntelligenceArea::Performance->value => Business\PerformanceAnalysis::class,
        IntelligenceArea::Forecasting->value => Business\ForecastingAnalysis::class,
        IntelligenceArea::DataQuality->value => Business\DataQualityAnalysis::class,
        IntelligenceArea::SystemHealth->value => System\SystemHealthAnalysis::class,
        IntelligenceArea::Errors->value => System\ErrorAnalysis::class,
        IntelligenceArea::ApplicationHealth->value => System\ApplicationHealthAnalysis::class,
        IntelligenceArea::DatabaseHealth->value => System\DatabaseHealthAnalysis::class,
        IntelligenceArea::QueueHealth->value => System\QueueHealthAnalysis::class,
        IntelligenceArea::IntegrationHealth->value => System\IntegrationHealthAnalysis::class,
        IntelligenceArea::DeploymentAnalysis->value => System\DeploymentAnalysis::class,
        IntelligenceArea::Predictive->value => System\PredictiveAnalysis::class,
    ];

    public function analysisFor(IntelligenceArea $area): ?AreaAnalysis
    {
        $class = self::ANALYSES[$area->value] ?? null;

        if ($class === null || ! class_exists($class)) {
            return null;
        }

        $analysis = app($class);

        return $analysis instanceof AreaAnalysis ? $analysis : null;
    }

    public function has(IntelligenceArea $area): bool
    {
        return $this->analysisFor($area) !== null;
    }
}
