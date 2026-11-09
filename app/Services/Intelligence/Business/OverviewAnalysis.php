<?php

declare(strict_types=1);

namespace App\Services\Intelligence\Business;

use App\Services\Intelligence\Insight;
use App\Services\Intelligence\IntelligenceAnalysis;
use App\Services\Intelligence\IntelligencePermissions;
use App\Services\SituationRoom\OrganizationRollup;
use App\Services\SituationRoom\SituationContext;
use App\Services\SituationRoom\SituationPanel;
use App\Services\SituationRoom\SituationSettings;
use Throwable;

/**
 * The Overview: one headline strip per business area the viewer may open
 * (an area they may not open is simply absent — no notice, no hint that it
 * exists) plus the most severe insights those areas raise, and, when the
 * technical domain is built and the viewer holds its grant, the System
 * Health headline. Each headline is its own panel closure, so one failing
 * area is one ERROR card.
 */
final class OverviewAnalysis extends IntelligenceAnalysis
{
    use BusinessQueries;

    public const MAX_INSIGHTS = 8;

    public const SYSTEM_HEALTH_CLASS = 'App\\Services\\Intelligence\\System\\SystemHealthAnalysis';

    public function __construct(
        OrganizationRollup $rollup,
        SituationSettings $settings,
        private readonly ExecutiveAnalysis $executive,
        private readonly WorkforceAnalysis $workforce,
        private readonly ServiceAnalysis $service,
        private readonly PublicVoiceAnalysis $publicVoice,
        private readonly PerformanceAnalysis $performance,
        private readonly ForecastingAnalysis $forecasting,
        private readonly DataQualityAnalysis $dataQuality,
    ) {
        parent::__construct($rollup, $settings);
    }

    public function panels(SituationContext $context): array
    {
        $panels = [];
        foreach ($this->areas($context) as $key => $analysis) {
            $panels["overview_{$key}"] = fn (): SituationPanel => $analysis->headline($context);
        }

        if ($context->can(IntelligencePermissions::SYSTEM_HEALTH) && class_exists(self::SYSTEM_HEALTH_CLASS) && method_exists(self::SYSTEM_HEALTH_CLASS, 'headline')) {
            $panels['overview_system_health'] = fn (): SituationPanel => app(self::SYSTEM_HEALTH_CLASS)->headline($context);
        }

        return $panels;
    }

    public function insights(SituationContext $context, array $panels): array
    {
        $insights = [];
        foreach ($this->areas($context) as $key => $analysis) {
            // The Executive view already aggregates the others; counting it would list every insight twice.
            if ($key === 'executive') {
                continue;
            }
            try {
                $insights = [...$insights, ...$analysis->insights($context, [])];
            } catch (Throwable $e) {
                report($e);
            }
        }
        usort($insights, fn (Insight $a, Insight $b): int => $b->weight() <=> $a->weight());

        return array_slice($insights, 0, self::MAX_INSIGHTS);
    }

    /**
     * The business analyses the viewer may open, in launcher order.
     *
     * @return array<string, ExecutiveAnalysis|WorkforceAnalysis|ServiceAnalysis|PublicVoiceAnalysis|PerformanceAnalysis|ForecastingAnalysis|DataQualityAnalysis>
     */
    private function areas(SituationContext $context): array
    {
        $all = [
            'executive' => [IntelligencePermissions::EXECUTIVE, $this->executive],
            'workforce' => [IntelligencePermissions::WORKFORCE, $this->workforce],
            'service' => [IntelligencePermissions::SERVICE, $this->service],
            'public_voice' => [IntelligencePermissions::PUBLIC_VOICE, $this->publicVoice],
            'performance' => [IntelligencePermissions::PERFORMANCE, $this->performance],
            'forecasting' => [IntelligencePermissions::FORECASTING, $this->forecasting],
            'data_quality' => [IntelligencePermissions::DATA_QUALITY, $this->dataQuality],
        ];
        $areas = [];
        foreach ($all as $key => [$permission, $analysis]) {
            if ($context->can($permission)) {
                $areas[$key] = $analysis;
            }
        }

        return $areas;
    }
}
